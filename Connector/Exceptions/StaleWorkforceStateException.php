<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/**
 * A connector feature asked to rely on workforce projections that are older
 * than the configured maximum, or that were never synchronised. Callers that
 * authorise or route on organisation state must let this propagate rather
 * than fall back to whatever the projection tables currently hold.
 */
final class StaleWorkforceStateException extends \RuntimeException {}
