<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

use App\Domains\PeopleConnector\Connector\Exceptions\CorruptWorkforcePageException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthenticationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderTemporaryException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderUnknownOutcomeException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use Throwable;

/**
 * Why a webhook-triggered sync pass failed, as a reason code (#223).
 *
 * The exception message is never stored: adapter exceptions can carry
 * response fragments, URLs and, on a bad-auth path, the credential itself
 * (docs/contracts/diagnostic-privacy.md). The class name is kept for
 * granularity; the code answers the operator's question, whether a replay
 * can succeed this time.
 */
enum WebhookDeliveryFailure: string
{
    case PageCorrupt = 'page_corrupt';
    case SyncRefused = 'sync_refused';
    case ProviderRefused = 'provider_refused';
    case ProviderUnavailable = 'provider_unavailable';
    case AnswerLost = 'answer_lost';
    case ProviderFailed = 'provider_failed';
    case Unexpected = 'unexpected';

    public static function for(Throwable $failure): self
    {
        return match (true) {
            $failure instanceof CorruptWorkforcePageException => self::PageCorrupt,
            $failure instanceof WorkforceSyncException => self::SyncRefused,
            $failure instanceof ProviderAuthenticationException,
            $failure instanceof ProviderAuthorizationException => self::ProviderRefused,
            $failure instanceof ProviderTemporaryException => self::ProviderUnavailable,
            $failure instanceof ProviderUnknownOutcomeException => self::AnswerLost,
            $failure instanceof ProviderException => self::ProviderFailed,
            default => self::Unexpected,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PageCorrupt => 'A feed page failed its integrity check',
            self::SyncRefused => 'The sync pass refused to run (connection, provider or checkpoint state)',
            self::ProviderRefused => 'The provider refused authentication or authorization',
            self::ProviderUnavailable => 'The provider was temporarily unavailable',
            self::AnswerLost => 'The provider answer was lost; outcome unknown',
            self::ProviderFailed => 'The provider call failed',
            self::Unexpected => 'An unexpected error',
        };
    }
}
