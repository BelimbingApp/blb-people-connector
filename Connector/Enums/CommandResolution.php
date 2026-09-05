<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

/**
 * What an operator established about a command the connector could not settle.
 *
 * Both values close the issue and neither resends anything. The connector
 * refused to guess in the first place, so a person guessing on its behalf would
 * be the same defect with a human in the loop: this records a finding, made out
 * of band against the provider, and nothing more.
 */
enum CommandResolution: string
{
    case ConfirmedDelivered = 'confirmed_delivered';
    case ConfirmedNotDelivered = 'confirmed_not_delivered';
}
