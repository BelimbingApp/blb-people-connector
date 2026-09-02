<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\SkillCategory;
use Illuminate\Support\Facades\DB;

/**
 * Installs the workbook's controlled vocabulary for one company: the ten
 * controlled skill categories and the standard 0-5 proficiency scale with its
 * behavioural anchors. Idempotent — existing codes are left untouched, so a
 * re-run never duplicates or overwrites HR's edits.
 *
 * Level 0 deliberately means "not trained": no demonstrated knowledge or
 * authorised experience. It is a real assessed outcome, not a placeholder —
 * "not yet assessed" is represented by the absence of a score, never by 0.
 */
class SkillCatalogDefaults
{
    public const SCALE_CODE = 'standard';

    private const CATEGORIES = [
        ['code' => 'safety', 'name' => 'Safety'],
        ['code' => 'quality', 'name' => 'Quality'],
        ['code' => 'process_technical', 'name' => 'Process/Technical'],
        ['code' => 'machine_equipment', 'name' => 'Machine/Equipment'],
        ['code' => 'digital_data_ai', 'name' => 'Digital/Data/AI'],
        ['code' => 'problem_solving', 'name' => 'Problem Solving'],
        ['code' => 'leadership', 'name' => 'Leadership'],
        ['code' => 'customer_application', 'name' => 'Customer/Application'],
        ['code' => 'sustainability', 'name' => 'Sustainability'],
        ['code' => 'work_discipline', 'name' => 'Work Discipline'],
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkillCatalogStore $catalog,
        private readonly ProficiencyScaleStore $scales,
    ) {}

    /**
     * @return array{categories: int, scale: ?ProficiencyScale}
     */
    public function install(int $companyEntityId): array
    {
        return DB::transaction(function () use ($companyEntityId): array {
            $created = 0;
            $tenantId = $this->tenantContext->requireTenantId();

            foreach (self::CATEGORIES as $category) {
                $exists = SkillCategory::query()
                    ->forTenant($tenantId)
                    ->where('company_entity_id', $companyEntityId)
                    ->where('code', $category['code'])
                    ->exists();

                if (! $exists) {
                    $this->catalog->defineCategory($companyEntityId, $category['code'], $category['name']);
                    $created++;
                }
            }

            $scale = null;
            if ($this->scales->currentScale($companyEntityId, self::SCALE_CODE) === null) {
                $draft = $this->scales->draft(
                    $companyEntityId,
                    self::SCALE_CODE,
                    'Standard Proficiency Scale',
                    $this->standardLevels(),
                );
                $scale = $this->scales->publish($companyEntityId, (int) $draft->getKey());
            }

            return ['categories' => $created, 'scale' => $scale];
        });
    }

    /**
     * @return list<ProficiencyLevelDraft>
     */
    public function standardLevels(): array
    {
        return [
            new ProficiencyLevelDraft(
                0,
                'Not trained',
                'No demonstrated knowledge or authorised experience.',
                'No independent work or training authority.',
            ),
            new ProficiencyLevelDraft(
                1,
                'Aware',
                'Explains the purpose and basic steps with guidance.',
                'Not qualified to perform the work.',
            ),
            new ProficiencyLevelDraft(
                2,
                'Supervised',
                'Performs the work correctly with direct supervision.',
                'Cannot work alone.',
            ),
            new ProficiencyLevelDraft(
                3,
                'Competent',
                'Works independently to the approved standard and escalates abnormalities.',
                'May work alone; not authorised to train others.',
            ),
            new ProficiencyLevelDraft(
                4,
                'Advanced',
                'Handles non-routine problems, improves the process, and coaches.',
                'May work alone and train others, subject to HOD scope.',
            ),
            new ProficiencyLevelDraft(
                5,
                'Expert / Authoriser',
                'Defines standards, certifies others, and leads complex technical decisions.',
                'Formal authority approval is still required.',
            ),
        ];
    }
}
