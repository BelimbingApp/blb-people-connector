<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum Hr2000CompanyAxis: string
{
    case Unverified = 'unverified';
    case OnePerPlatformCompany = 'one_per_platform_company';
    case FinerThanPlatform = 'finer_than_platform';
    case CoarserThanPlatform = 'coarser_than_platform';
}
