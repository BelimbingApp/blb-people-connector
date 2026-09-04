<?php

namespace App\Domains\PeopleConnector\Training\Contracts;

use App\Domains\PeopleConnector\Training\Data\TrainingParticipationSummary;

interface SummarizesTrainingParticipation
{
    /**
     * @param  list<int>  $trainingEventIds
     * @return array<int, TrainingParticipationSummary>
     */
    public function forEvents(int $companyEntityId, array $trainingEventIds): array;
}
