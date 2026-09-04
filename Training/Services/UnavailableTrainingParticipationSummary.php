<?php

namespace App\Domains\PeopleConnector\Training\Services;

use App\Domains\PeopleConnector\Training\Contracts\SummarizesTrainingParticipation;

/** Default until People #34 supplies authoritative participant facts. */
final class UnavailableTrainingParticipationSummary implements SummarizesTrainingParticipation
{
    public function forEvents(int $companyEntityId, array $trainingEventIds): array
    {
        return [];
    }
}
