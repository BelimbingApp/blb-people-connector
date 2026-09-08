<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Contracts\RefusesDeletion;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;

/**
 * One file a connection received or produced, recorded before anything
 * processed it (#263). What the row binds — tenant, company, connection,
 * direction, operation, name, hash, size, actor, time — is fixed at insert.
 * Only the status and its reason move, and never through a delete: a record
 * that could be removed would let changed bytes inherit an earlier approval.
 */
final class FileExchangeRecord extends TenantOwnedModel implements RefusesDeletion
{
    public const DIRECTION_IMPORT = 'import';

    public const DIRECTION_EXPORT = 'export';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const DIRECTIONS = [self::DIRECTION_IMPORT, self::DIRECTION_EXPORT];

    /** @var list<string> */
    public const STATUSES = [self::STATUS_RECORDED, self::STATUS_QUARANTINED, self::STATUS_ARCHIVED];

    /** @var list<string> */
    public const MUTABLE = ['status', 'status_reason', 'updated_at'];

    protected $table = 'people_connector_connector_file_exchange_records';

    protected static function booted(): void
    {
        self::saving(function (FileExchangeRecord $record): void {
            if (! in_array($record->direction, self::DIRECTIONS, true)) {
                throw new AppendOnlyRecordException('A file exchange record direction is import or export.');
            }

            if (! in_array($record->status, self::STATUSES, true)) {
                throw new AppendOnlyRecordException('A file exchange record status is recorded, quarantined or archived.');
            }
        });

        self::updating(function (FileExchangeRecord $record): void {
            $frozen = array_values(array_diff(array_keys($record->getDirty()), self::MUTABLE));

            if ($frozen !== []) {
                throw new AppendOnlyRecordException('A file exchange record is immutable; only its status may change (attempted: '.implode(', ', $frozen).').');
            }
        });

        self::deleting(function (): void {
            throw new AppendOnlyRecordException('File exchange records cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'provider_connection_id' => 'integer',
            'byte_length' => 'integer',
            'actor_user_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
