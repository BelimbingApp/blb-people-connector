<?php

namespace App\Domains\PeopleConnector\Skill\Contracts;

use App\Domains\PeopleConnector\Skill\Data\ResolvedSkillRequirement;
use DateTimeInterface;

/**
 * Provider-neutral skill requirements for an employee as of a date.
 *
 * Assessment and development-action modules depend on this seam only — never
 * on requirement-profile selectors, tiers, or store internals (blb-people#80).
 */
interface ResolvesSkillRequirements
{
    /**
     * @param  array<string, mixed>  $employeeData  Workforce attributes the
     *                                              resolver needs (company, and
     *                                              whatever selectors use).
     * @return list<ResolvedSkillRequirement>
     */
    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array;
}
