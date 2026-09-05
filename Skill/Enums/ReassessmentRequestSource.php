<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

enum ReassessmentRequestSource: string
{
    case DevelopmentAction = 'development_action';
    case TrainingEvent = 'training_event';
    case Manual = 'manual';
    case CertificationExpiry = 'certification_expiry';
}
