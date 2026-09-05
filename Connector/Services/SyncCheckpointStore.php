<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Exceptions\SyncCheckpointConflictException;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpointEvent;
use Illuminate\Support\Facades\DB;

final class SyncCheckpointStore
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionLocator $connections,
    ) {}

    public function current(int $connectionId, string $stream): ?SyncCheckpoint
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->connections->get($connectionId);
        $this->assertStream($stream);

        return SyncCheckpoint::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->where('stream', $stream)
            ->first();
    }

    /**
     * The resume cursor this stream stood at after the given version.
     *
     * The version history is append-only, so an older version is still readable
     * long after the checkpoint itself has moved past it. Null means the
     * connection never reached that version on this stream.
     */
    public function cursorAtVersion(int $connectionId, string $stream, int $version): ?string
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->connections->get($connectionId);
        $this->assertStream($stream);

        $checkpointId = SyncCheckpoint::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->where('stream', $stream)
            ->value('id');

        if ($checkpointId === null) {
            return null;
        }

        $cursor = SyncCheckpointEvent::query()
            ->forTenant($tenantId)
            ->where('checkpoint_id', $checkpointId)
            ->where('version', $version)
            ->value('to_cursor');

        return $cursor === null ? null : (string) $cursor;
    }

    public function advanceCompletedPage(
        int $connectionId,
        string $stream,
        WorkforceChangePage $page,
        int $expectedVersion,
        ?\DateTimeInterface $completedAt = null,
    ): SyncCheckpoint {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertStream($stream);

        if (! $page->complete || $page->resumeCursor === null) {
            throw new SyncCheckpointConflictException(
                'A durable sync checkpoint advances only after the final page is applied successfully.',
            );
        }

        if ($expectedVersion < 0) {
            throw new SyncCheckpointConflictException('Sync checkpoint versions cannot be negative.');
        }

        return DB::transaction(function () use ($connectionId, $stream, $page, $expectedVersion, $completedAt, $tenantId): SyncCheckpoint {
            ConnectionRetirementService::assertWritable($this->connections->get($connectionId, lock: true));
            $checkpoint = SyncCheckpoint::query()
                ->forTenant($tenantId)
                ->where('connection_id', $connectionId)
                ->where('stream', $stream)
                ->lockForUpdate()
                ->first();

            if ($checkpoint !== null
                && $checkpoint->resume_cursor === $page->resumeCursor
                && $checkpoint->as_of_at->equalTo($page->asOf)) {
                return $checkpoint;
            }

            if ($checkpoint !== null && $checkpoint->as_of_at->greaterThan($page->asOf)) {
                throw new SyncCheckpointConflictException(
                    'A durable sync checkpoint cannot move its provider as-of watermark backward.',
                );
            }

            $currentVersion = (int) ($checkpoint?->version ?? 0);

            if ($currentVersion !== $expectedVersion) {
                throw new SyncCheckpointConflictException(
                    "Sync checkpoint version conflict: expected {$expectedVersion}, current {$currentVersion}.",
                );
            }

            $finishedAt = $completedAt ?? now();
            $nextVersion = $currentVersion + 1;
            $fromCursor = $checkpoint?->resume_cursor;

            if ($checkpoint === null) {
                $checkpoint = SyncCheckpoint::query()->create([
                    'tenant_id' => $tenantId,
                    'connection_id' => $connectionId,
                    'stream' => $stream,
                    'resume_cursor' => $page->resumeCursor,
                    'version' => $nextVersion,
                    'as_of_at' => $page->asOf,
                    'completed_at' => $finishedAt,
                ]);
            } else {
                $checkpoint->fill([
                    'resume_cursor' => $page->resumeCursor,
                    'version' => $nextVersion,
                    'as_of_at' => $page->asOf,
                    'completed_at' => $finishedAt,
                ])->save();
            }

            SyncCheckpointEvent::query()->create([
                'tenant_id' => $tenantId,
                'checkpoint_id' => $checkpoint->id,
                'version' => $nextVersion,
                'from_cursor' => $fromCursor,
                'to_cursor' => $page->resumeCursor,
                'as_of_at' => $page->asOf,
                'completed_at' => $finishedAt,
                'created_at' => now(),
            ]);

            return $checkpoint->refresh();
        });
    }

    private function assertStream(string $stream): void
    {
        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $stream) !== 1 || strlen($stream) > 100) {
            throw new SyncCheckpointConflictException('Sync checkpoint streams require a stable lowercase identifier.');
        }
    }
}
