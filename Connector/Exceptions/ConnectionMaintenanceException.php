<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/** A maintenance window request the connector refuses, or a pass held by one (#264). */
final class ConnectionMaintenanceException extends \RuntimeException {}
