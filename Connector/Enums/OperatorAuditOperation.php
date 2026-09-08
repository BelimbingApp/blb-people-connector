<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

/** Privileged connector operator actions that leave an audit row (#199). */
enum OperatorAuditOperation: string
{
    case SyncPass = 'sync.pass';
    case ConnectionRetired = 'connection.retired';
    case ConnectionMaintenance = 'connection.maintenance';
    case IdentitiesRemapped = 'provider.identities_remapped';
    case IdentitiesRemapRolledBack = 'provider.identities_remap_rolled_back';
    case CutoverRehearsed = 'cutover.rehearsed';
    case RetentionPurged = 'retention.purged';
    case SubjectHistoryExported = 'subject.history_exported';
    case SubjectHistoryImported = 'subject.history_imported';
    case WebhookReplayed = 'webhook.replayed';
    case CapabilityVerified = 'capability.verified';
    case WebhookSecretRotated = 'webhook.secret_rotated';
    case SupportBundled = 'support.bundled';
    case FileExchangeRecorded = 'file_exchange.recorded';
    case FileExchangeDiscovered = 'file_exchange.discovered';
    case ConnectionResidueReported = 'connection.residue_reported';

    public function label(): string
    {
        return match ($this) {
            self::SyncPass => 'Workforce sync pass',
            self::ConnectionRetired => 'Connection retired',
            self::ConnectionMaintenance => 'Connection maintenance window changed',
            self::IdentitiesRemapped => 'Identities remapped to a replacement connection',
            self::IdentitiesRemapRolledBack => 'Identity remap rolled back to the source connection',
            self::CutoverRehearsed => 'Cutover rehearsed',
            self::RetentionPurged => 'Retention purge executed',
            self::SubjectHistoryExported => 'Workforce subject history exported',
            self::SubjectHistoryImported => 'Workforce subject history imported',
            self::WebhookReplayed => 'Webhook delivery replayed',
            self::CapabilityVerified => 'Provider capability evidence recorded',
            self::WebhookSecretRotated => 'Webhook signing secret rotated',
            self::SupportBundled => 'Support bundle written',
            self::FileExchangeRecorded => 'File exchange record written',
            self::FileExchangeDiscovered => 'Inbound file exchange directory discovered',
            self::ConnectionResidueReported => 'Connection residue reported',
        };
    }
}
