<?php

namespace App\Domains\PeopleConnector\Training\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingRequestException;

/** Immutable audit trail; the request itself contains only current workflow state. */
final class TrainingRequestDecision extends TenantOwnedModel
{
    use CompanyOwned;

    public $timestamps = false;

    protected $table = 'people_connector_training_request_decisions';

    protected static function booted(): void
    {
        $immutable = fn (): never => throw new InvalidTrainingRequestException('Training request decisions are append-only.');
        self::updating($immutable);
        self::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
