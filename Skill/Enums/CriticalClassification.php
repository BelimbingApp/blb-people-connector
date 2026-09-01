<?php

namespace App\Domains\PeopleConnector\Skill\Enums;

/**
 * Why a skill is critical. A skill with no classification is not critical;
 * criticality is derived from the classification rather than stored twice.
 */
enum CriticalClassification: string
{
    case Safety = 'safety';
    case Quality = 'quality';
    case Integrity = 'integrity';
}
