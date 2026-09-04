<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/**
 * A synchronisation pass could not run at all: the connection and adapter do
 * not match, an incremental pass was asked for before any bootstrap completed,
 * or the adapter's paging contradicted itself. Per-record problems are never
 * raised as this; they become reconciliation issues so the pass keeps going.
 */
final class WorkforceSyncException extends \RuntimeException {}
