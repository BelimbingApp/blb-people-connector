<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\Services\DataShare\DataShareDestinationMapper;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareValueNormalizer;
use App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus;
use App\Domains\PeopleConnector\Skill\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Support\Facades\DB;

/**
 * Bridges verified DataShare inserts to the requirement-profile DB guards.
 *
 * DataShareDestinationMapper::bindableValues() is invoked only by the
 * package applier after its hash/plan checks and inside its apply transaction.
 * Every proof is bound to one exact incoming row and consumed by its trigger.
 */
final class RequirementProfileDataShareDestinationMapper extends DataShareDestinationMapper
{
    private const PROFILE_TABLE = 'people_connector_skill_requirement_profiles';

    private const GUARDED_CHILD_TABLES = [
        'people_connector_skill_requirement_items',
        'people_connector_skill_requirement_profile_selectors',
    ];

    public function __construct(
        DataShareValueNormalizer $values,
        DataShareScopeCatalog $catalog,
        private readonly RequirementProfileTransitionAuthority $authority,
    ) {
        parent::__construct($values, $catalog);
    }

    /**
     * @param  array<string, mixed>  $desired
     * @return array<string, mixed>
     */
    public function bindableValues(DataShareTableDefinition $table, array $desired): array
    {
        $values = parent::bindableValues($table, $desired);

        if ($table->table === self::PROFILE_TABLE
            && ($values['status'] ?? null) !== RequirementProfileStatus::Draft->value) {
            $this->authority->authorizeDatabaseRestore($table->table, $values);
        }

        if (in_array($table->table, self::GUARDED_CHILD_TABLES, true)
            && $this->restoredParentIsGoverned($values)) {
            $this->authority->authorizeDatabaseRestore($table->table, $values);
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function restoredParentIsGoverned(array $values): bool
    {
        $tenantId = (int) ($values['tenant_id'] ?? 0);
        $profileId = (int) ($values['profile_id'] ?? 0);

        if ($tenantId < 1 || $profileId < 1) {
            return false;
        }

        return DB::table(self::PROFILE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $profileId)
            ->where('status', '!=', RequirementProfileStatus::Draft->value)
            ->exists();
    }
}
