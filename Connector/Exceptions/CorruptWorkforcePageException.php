<?php

namespace App\Domains\PeopleConnector\Connector\Exceptions;

/**
 * A feed page's declared checksum does not match its content. The page was
 * refused before any of it was projected and the checkpoint did not move; a
 * sync_page_corrupt reconciliation issue names the page for an operator.
 */
final class CorruptWorkforcePageException extends WorkforceSyncException {}
