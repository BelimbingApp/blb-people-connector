<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\RetentionReport;
use App\Domains\PeopleConnector\Connector\Data\RetentionTableReport;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long the connector keeps each of its own tables, and how much is already
 * past that.
 *
 * This reports and stops. Deleting is a separate, separately approved step, and
 * keeping the two apart is the point of the lane: an operator has to be able to
 * see what a purge would touch before anyone writes the purge.
 */
final class RetentionPolicy
{
    public const REVIEW_CAPABILITY = 'people-connector.retention.review';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {}

    public function review(Actor $actor, ?\DateTimeImmutable $now = null): RetentionReport
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::REVIEW_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'review_retention',
                message: 'A retention review reports one tenant\'s rows and requires an actor inside it.',
            );
        }

        $reviewedAt = $now ?? \DateTimeImmutable::createFromInterface(now());
        $owned = self::ownedTables();
        $tables = [];

        foreach (self::declaredPolicy() as $table => $rule) {
            // A retention entry is what a later lane will delete from. A typo
            // that quietly matched nothing would look like "nothing to purge"
            // forever, and one naming another domain's table would be a licence
            // to delete rows this domain has no claim on.
            if (! in_array($table, $owned, true)) {
                throw new RetentionPolicyException(
                    "Retention can only be declared for connector-owned tables; [{$table}] is not one.",
                );
            }

            if ($rule['column'] !== null && ! Schema::hasColumn($table, $rule['column'])) {
                throw new RetentionPolicyException(
                    "Retention for [{$table}] names column [{$rule['column']}], which that table does not have.",
                );
            }

            $tables[$table] = new RetentionTableReport(
                $table,
                $rule['days'],
                $rule['column'],
                $rule['days'] === null ? 0 : $this->expiredCount($table, $rule['column'], $tenantId, $reviewedAt, $rule['days']),
            );
        }

        return new RetentionReport($tenantId, $reviewedAt, $tables);
    }

    private function expiredCount(string $table, string $column, int $tenantId, \DateTimeImmutable $reviewedAt, int $days): int
    {
        return DB::table($table)
            ->where('tenant_id', $tenantId)
            ->whereNotNull($column)
            ->where($column, '<', $reviewedAt->modify("-{$days} days"))
            ->count();
    }

    /**
     * The declared policy, validated into a shape the review can rely on.
     *
     * @return array<string, array{days: int|null, column: string|null}>
     */
    private static function declaredPolicy(): array
    {
        $declared = config('people-connector.retention', []);

        if (! is_array($declared)) {
            throw new RetentionPolicyException('people-connector.retention must be a table-keyed array.');
        }

        $policy = [];

        foreach ($declared as $table => $rule) {
            if (! is_string($table) || ! is_array($rule) || ! array_key_exists('days', $rule)) {
                throw new RetentionPolicyException('Each retention entry needs a table and a days value.');
            }

            $column = $rule['column'] ?? null;

            if ($rule['days'] !== null && (! is_string($column) || $column === '')) {
                throw new RetentionPolicyException(
                    "Retention for [{$table}] keeps rows for a period, so it must name the column that period is measured from.",
                );
            }

            if ($rule['days'] !== null && (! is_int($rule['days']) || $rule['days'] < 1)) {
                throw new RetentionPolicyException(
                    "Retention for [{$table}] must be a positive number of days, or null for indefinite.",
                );
            }

            $policy[$table] = ['days' => $rule['days'], 'column' => is_string($column) ? $column : null];
        }

        return $policy;
    }

    /**
     * Tables this domain owns, taken from the models that declare them rather
     * than from the table-name prefix. A model is the domain saying "this is
     * mine"; a prefix is a convention anyone can type.
     *
     * @return list<string>
     */
    private static function ownedTables(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $model): string => (new $model)->getTable(),
            array_filter(DomainModels::all(), static fn (string $model): bool => is_subclass_of($model, Model::class)),
        )));
    }
}
