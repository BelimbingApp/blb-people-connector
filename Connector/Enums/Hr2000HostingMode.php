<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum Hr2000HostingMode: string
{
    case Unverified = 'unverified';
    case Hosted = 'hosted';
    case OnPremise = 'on_premise';
}
