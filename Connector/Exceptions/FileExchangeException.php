<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

use RuntimeException;

/** A file exchange record was refused: bytes that do not match, a connection outside the tenant or already retired, or a status that cannot change. */
final class FileExchangeException extends RuntimeException {}
