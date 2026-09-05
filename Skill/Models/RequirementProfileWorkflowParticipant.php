<?php

namespace App\Domains\PeopleConnector\Skill\Models;

/**
 * Workflow Engine participant view of RequirementProfile.
 *
 * The domain model exposes status as a backed enum, while Base Workflow's
 * generic engine intentionally operates on persisted string codes. This
 * internal table view keeps those interfaces deep and truthful without
 * weakening either contract.
 */
final class RequirementProfileWorkflowParticipant extends RequirementProfile
{
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => 'string',
            'effective_date' => 'date',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }
}
