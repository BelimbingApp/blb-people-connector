<?php

namespace App\Domains\PeopleConnector\Connector\Notifications;

use App\Domains\PeopleConnector\Connector\Models\ConnectorDoctorAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Ramsey\Uuid\Uuid;

/**
 * One doctor incident alert (#257): a check that has been red on two
 * consecutive snapshots, or has gone green again after such an incident.
 * The payload is the doctor row itself, a check name, a count and a
 * connector-authored detail; no exception text ever reaches it
 * (docs/contracts/diagnostic-privacy.md).
 */
final class ConnectorDoctorAlertNotification extends Notification
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $check,
        public readonly string $kind,
        public readonly int $count,
        public readonly string $detail,
        public readonly \DateTimeImmutable $firstRedAt,
        public readonly string $channel,
    ) {
        // Deterministic per incident and recipient, so a re-delivered
        // notification cannot become a second database row.
        $this->id = Uuid::uuid5(Uuid::NAMESPACE_URL, implode(':', [self::class, $tenantId, $check, $firstRedAt->format(DATE_ATOM), $kind]))->toString();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'check' => $this->check,
            'kind' => $this->kind,
            'count' => $this->count,
            'detail' => $this->detail,
            'first_red_at' => $this->firstRedAt->format(DATE_ATOM),
            'title' => $this->title(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line("Check: {$this->check}")
            ->line("Detail: {$this->detail}")
            ->line('First red at: '.$this->firstRedAt->format(DATE_ATOM));
    }

    public function title(): string
    {
        return $this->kind === ConnectorDoctorAlert::KIND_RECOVERED
            ? "Connector doctor: {$this->check} is green again"
            : "Connector doctor: {$this->check} is red";
    }
}
