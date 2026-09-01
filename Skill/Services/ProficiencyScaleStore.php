<?php

namespace App\Domains\PeopleConnector\Skill\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft;
use App\Domains\PeopleConnector\Skill\Enums\ProficiencyScaleStatus;
use App\Domains\PeopleConnector\Skill\Events\ProficiencyScalePublished;
use App\Domains\PeopleConnector\Skill\Exceptions\InvalidSkillCatalogException;
use App\Domains\PeopleConnector\Skill\Exceptions\ProficiencyScaleStateException;
use App\Domains\PeopleConnector\Skill\Exceptions\SkillCatalogRecordNotFoundException;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScale;
use App\Domains\PeopleConnector\Skill\Models\ProficiencyScaleLevel;
use Illuminate\Support\Facades\DB;

/**
 * Versioned proficiency-scale lifecycle: draft → published → retired.
 * Publishing retires the previously published version of the same code, so
 * exactly one version of a scale code is current per company; published
 * versions never mutate (enforced in the models), so historical scores keep
 * their meaning.
 */
class ProficiencyScaleStore
{
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_.\-]{0,79}$/';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  list<ProficiencyLevelDraft>  $levels
     */
    public function draft(int $companyEntityId, string $code, string $name, array $levels): ProficiencyScale
    {
        $tenantId = $this->tenantContext->requireTenantId();

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new InvalidSkillCatalogException(
                'A scale code must be 1-80 lowercase letters, digits, dots, dashes, or underscores, starting with a letter or digit.',
            );
        }

        $this->assertCompanyEntity($tenantId, $companyEntityId);
        $this->assertLevels($levels);

        return DB::transaction(function () use ($tenantId, $companyEntityId, $code, $name, $levels): ProficiencyScale {
            $currentMax = (int) ProficiencyScale::query()
                ->forTenant($tenantId)
                ->where('company_entity_id', $companyEntityId)
                ->where('code', $code)
                ->max('version');

            if ($this->draftOf($tenantId, $companyEntityId, $code) !== null) {
                throw new ProficiencyScaleStateException(
                    "Scale [$code] already has an open draft; publish or discard it before drafting again.",
                );
            }

            $scale = ProficiencyScale::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'code' => $code,
                'name' => $name,
                'version' => $currentMax + 1,
                'status' => ProficiencyScaleStatus::Draft,
            ]);

            $this->writeLevels($scale, $levels);

            return $scale;
        });
    }

    /**
     * Copy a scale's levels into a new draft version of the same code, ready
     * for revision. This is the only way to change a published scale.
     */
    public function newDraftFrom(int $scaleId): ProficiencyScale
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $source = $this->requireScale($tenantId, $scaleId);

        $levels = $source->levels()->get()
            ->map(fn (ProficiencyScaleLevel $level): ProficiencyLevelDraft => new ProficiencyLevelDraft(
                (int) $level->level,
                (string) $level->name,
                (string) $level->anchor,
                (string) $level->authority,
            ))
            ->all();

        return $this->draft((int) $source->company_entity_id, (string) $source->code, (string) $source->name, $levels);
    }

    public function publish(int $scaleId): ProficiencyScale
    {
        $tenantId = $this->tenantContext->requireTenantId();

        return DB::transaction(function () use ($tenantId, $scaleId): ProficiencyScale {
            $scale = $this->requireScale($tenantId, $scaleId);

            if ($scale->status !== ProficiencyScaleStatus::Draft) {
                throw new ProficiencyScaleStateException(
                    "Scale [{$scale->code}] v{$scale->version} is {$scale->status->value}; only a draft can be published.",
                );
            }

            $this->assertLevels(
                $scale->levels()->get()
                    ->map(fn (ProficiencyScaleLevel $level): ProficiencyLevelDraft => new ProficiencyLevelDraft(
                        (int) $level->level,
                        (string) $level->name,
                        (string) $level->anchor,
                        (string) $level->authority,
                    ))
                    ->all(),
            );

            $previous = $this->publishedOf($tenantId, (int) $scale->company_entity_id, (string) $scale->code);
            $previous?->update([
                'status' => ProficiencyScaleStatus::Retired,
                'retired_at' => now(),
            ]);

            $scale->update([
                'status' => ProficiencyScaleStatus::Published,
                'published_at' => now(),
            ]);

            event(new ProficiencyScalePublished(
                $tenantId,
                (int) $scale->getKey(),
                (string) $scale->code,
                (int) $scale->version,
                $previous?->getKey() === null ? null : (int) $previous->getKey(),
            ));

            return $scale;
        });
    }

    public function retire(int $scaleId): ProficiencyScale
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $scale = $this->requireScale($tenantId, $scaleId);

        if ($scale->status !== ProficiencyScaleStatus::Published) {
            throw new ProficiencyScaleStateException(
                "Scale [{$scale->code}] v{$scale->version} is {$scale->status->value}; only a published scale can be retired.",
            );
        }

        $scale->update([
            'status' => ProficiencyScaleStatus::Retired,
            'retired_at' => now(),
        ]);

        return $scale;
    }

    /**
     * Delete an unpublished draft and its levels. Anything ever published is
     * immutable and refuses deletion at the model layer.
     */
    public function discardDraft(int $scaleId): void
    {
        $tenantId = $this->tenantContext->requireTenantId();

        DB::transaction(function () use ($tenantId, $scaleId): void {
            $scale = $this->requireScale($tenantId, $scaleId);

            if ($scale->status !== ProficiencyScaleStatus::Draft) {
                throw new ProficiencyScaleStateException(
                    "Scale [{$scale->code}] v{$scale->version} is {$scale->status->value}; only a draft can be discarded.",
                );
            }

            $scale->levels()->get()->each->delete();
            $scale->delete();
        });
    }

    public function currentScale(int $companyEntityId, string $code): ?ProficiencyScale
    {
        return $this->publishedOf($this->tenantContext->requireTenantId(), $companyEntityId, $code);
    }

    private function requireScale(int $tenantId, int $scaleId): ProficiencyScale
    {
        return ProficiencyScale::query()->forTenant($tenantId)->find($scaleId)
            ?? throw new SkillCatalogRecordNotFoundException("Proficiency scale [$scaleId] was not found.");
    }

    private function draftOf(int $tenantId, int $companyEntityId, string $code): ?ProficiencyScale
    {
        return ProficiencyScale::query()
            ->forTenant($tenantId)
            ->where('company_entity_id', $companyEntityId)
            ->where('code', $code)
            ->where('status', ProficiencyScaleStatus::Draft->value)
            ->first();
    }

    private function publishedOf(int $tenantId, int $companyEntityId, string $code): ?ProficiencyScale
    {
        return ProficiencyScale::query()
            ->forTenant($tenantId)
            ->where('company_entity_id', $companyEntityId)
            ->where('code', $code)
            ->where('status', ProficiencyScaleStatus::Published->value)
            ->first();
    }

    /**
     * @param  list<ProficiencyLevelDraft>  $levels
     */
    private function writeLevels(ProficiencyScale $scale, array $levels): void
    {
        foreach ($levels as $level) {
            ProficiencyScaleLevel::query()->create([
                'tenant_id' => $scale->tenant_id,
                'scale_id' => $scale->getKey(),
                'level' => $level->level,
                'name' => $level->name,
                'anchor' => $level->anchor,
                'authority' => $level->authority,
            ]);
        }
    }

    /**
     * @param  list<ProficiencyLevelDraft>  $levels
     */
    private function assertLevels(array $levels): void
    {
        if (count($levels) < 2) {
            throw new InvalidSkillCatalogException('A proficiency scale needs at least two levels.');
        }

        $numbers = array_map(fn (ProficiencyLevelDraft $level): int => $level->level, $levels);
        sort($numbers);

        if ($numbers !== range(0, count($levels) - 1)) {
            throw new InvalidSkillCatalogException('Scale levels must be contiguous and start at 0.');
        }

        $names = array_map(fn (ProficiencyLevelDraft $level): string => trim($level->name), $levels);
        if (count(array_unique($names)) !== count($names) || in_array('', $names, true)) {
            throw new InvalidSkillCatalogException('Every scale level needs a distinct, non-empty name.');
        }

        foreach ($levels as $level) {
            if (trim($level->anchor) === '' || trim($level->authority) === '') {
                throw new InvalidSkillCatalogException(
                    "Level {$level->level} needs a behavioural anchor and an authority statement.",
                );
            }
        }
    }

    private function assertCompanyEntity(int $tenantId, int $companyEntityId): void
    {
        $entity = WorkforceEntity::query()->forTenant($tenantId)->find($companyEntityId);

        if ($entity === null || $entity->resource_type !== WorkforceResourceType::Company->value) {
            throw new InvalidSkillCatalogException(
                'A proficiency scale must belong to an existing company workforce entity in this tenant.',
            );
        }
    }
}
