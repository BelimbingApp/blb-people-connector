<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

/** Privileged connector operator actions that leave an audit row (#199). */
enum OperatorAuditOperation: string
{
    case SyncPass = 'sync.pass';
    case ConnectionRetired = 'connection.retired';
    case IdentitiesRemapped = 'provider.identities_remapped';
    case CutoverRehearsed = 'cutover.rehearsed';
    case RetentionPurged = 'retention.purged';
    case SubjectHistoryExported = 'subject.history_exported';
    case SubjectHistoryImported = 'subject.history_imported';
    case WebhookReplayed = 'webhook.replayed';

    public function label(): string
    {
        return match ($this) {
            self::SyncPass => 'Workforce sync pass',
            self::ConnectionRetired => 'Connection retired',
            self::IdentitiesRemapped => 'Identities remapped to a replacement connection',
            self::CutoverRehearsed => 'Cutover rehearsed',
            self::RetentionPurged => 'Retention purge executed',
            self::SubjectHistoryExported => 'Workforce subject history exported',
            self::SubjectHistoryImported => 'Workforce subject history imported',
            self::WebhookReplayed => 'Webhook delivery replayed',
        };
    }
}
