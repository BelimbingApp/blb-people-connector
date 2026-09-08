<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

use RuntimeException;

/** An operator audit row was refused: wrong tenant, or a summary that is not a summary. */
class OperatorAuditException extends RuntimeException {}
