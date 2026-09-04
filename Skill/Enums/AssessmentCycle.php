<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum AssessmentCycle: string
{
    case Baseline = 'baseline';
    case Probation = 'probation';
    case Quarterly = 'quarterly';
    case Annual = 'annual';
    case PostTraining = 'post_training';
    case TransferPromotion = 'transfer_promotion';
    case Recertification = 'recertification';
}
