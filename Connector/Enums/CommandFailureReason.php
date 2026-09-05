<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

/**
 * Why a command did not settle cleanly, as a connector-owned code.
 *
 * Deliberately an enum and not a string. `docs/contracts/diagnostic-privacy.md`
 * settles that conflict handling "maps exception classes to reason codes rather
 * than persisting getMessage()", and a free-text reason on this outcome is the
 * obvious place an adapter's raw message would be handed forward — into a log,
 * a reconciliation record, or an operator's screen. There is nowhere to put one
 * here, so nobody has to remember not to.
 */
enum CommandFailureReason: string
{
    /** The transport gave up before the command left the connector. */
    case NotSent = 'not_sent';

    /** The command left and the transport failed before an answer arrived. */
    case AnswerLost = 'answer_lost';

    /** The provider answered, and its answer was a refusal. */
    case ProviderRefused = 'provider_refused';

    /** Reconciliation asked and the provider holds nothing under the key. */
    case AbsentAtProvider = 'absent_at_provider';
}
