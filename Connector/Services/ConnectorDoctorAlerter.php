<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\ConnectorDoctorReport;
use App\Domains\PeopleConnector\Connector\Models\ConnectorDoctorAlert;
use App\Domains\PeopleConnector\Connector\Notifications\ConnectorDoctorAlertNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns the recorded doctor snapshots into alerts (#257).
 *
 * A check alerts when it is red on the two latest snapshots, so a single
 * transient failure stays quiet, and again once when it is green after such
 * an incident. An incident is (tenant, check, first red at); the alert table's
 * unique key is what makes a rerun while still red send nothing.
 */
final class ConnectorDoctorAlerter
{
    /**
     * @param  object  $recipient  a notifiable inside the tenant, the operator the doctor runs as
     * @return list<string> one console line per alert sent
     */
    public function alert(int $tenantId, ConnectorDoctorReport $report, object $recipient, ?\DateTimeImmutable $now = null): array
    {
        $channel = config('people-connector.doctor.alert_channel');
        if (! is_string($channel) || $channel === '') {
            return ['Doctor alerts are disabled: people-connector.doctor.alert_channel is not set.'];
        }
        $now ??= \DateTimeImmutable::createFromInterface(now());
        $lines = [];

        foreach ($report->checks as $row) {
            $history = $this->history($tenantId, $row['check']);
            $latest = $history[0] ?? null;
            $previous = $history[1] ?? null;
            if ($latest === null || $previous === null || $previous->status !== 'red') {
                continue;
            }

            if ($latest->status === 'red') {
                $kind = ConnectorDoctorAlert::KIND_RED;
                $firstRedAt = $this->firstRedAt($history, 0);
            } else {
                $kind = ConnectorDoctorAlert::KIND_RECOVERED;
                $firstRedAt = $this->firstRedAt($history, 1);
                // A recovery only follows an incident that was alerted: red
                // once and then green was never announced, so neither is its end.
                $announced = ConnectorDoctorAlert::query()->forTenant($tenantId)
                    ->where('check', $row['check'])->where('first_red_at', $firstRedAt)->where('kind', ConnectorDoctorAlert::KIND_RED)->exists();
                if (! $announced) {
                    continue;
                }
            }

            if (! $this->claim($tenantId, $row['check'], $firstRedAt, $kind, $now)) {
                continue;
            }

            $recipient->notify(new ConnectorDoctorAlertNotification($tenantId, $row['check'], $kind, (int) $row['count'], (string) $row['detail'], $firstRedAt, $channel));
            $lines[] = "Alert sent: {$row['check']} {$kind} (first red at {$firstRedAt->format(DATE_ATOM)})";
        }

        return $lines;
    }

    /** @return list<object{status: string, measured_at: string}> newest first */
    private function history(int $tenantId, string $check): array
    {
        return DB::table('people_connector_connector_doctor_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('check', $check)
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->get(['status', 'measured_at'])
            ->all();
    }

    /** The start of the unbroken run of reds that includes $history[$from]. */
    private function firstRedAt(array $history, int $from): \DateTimeImmutable
    {
        $start = $history[$from];
        for ($i = $from; isset($history[$i]) && $history[$i]->status === 'red'; $i++) {
            $start = $history[$i];
        }

        return new \DateTimeImmutable((string) $start->measured_at);
    }

    /** Own transaction: on PostgreSQL a violated unique key aborts the enclosing one. */
    private function claim(int $tenantId, string $check, \DateTimeImmutable $firstRedAt, string $kind, \DateTimeImmutable $sentAt): bool
    {
        try {
            DB::transaction(static fn () => ConnectorDoctorAlert::query()->create([
                'tenant_id' => $tenantId, 'check' => $check, 'first_red_at' => $firstRedAt, 'kind' => $kind, 'sent_at' => $sentAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
