<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Exceptions\OperatorAuditException;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;

/**
 * The one writer of operator audit rows (#199; plan 0001: "record sensitive
 * access/export activity with actor, scope and operation without copying
 * sensitive contents into logs").
 *
 * A summary is a summary: scalar facts and short strings, keyed by plain
 * names. A key that names a credential, token or payload is refused outright,
 * as is a nested structure or a long string, because the safe way to keep a
 * secret out of an audit log is to make the log unable to hold one.
 */
final class OperatorAuditLog
{
    /**
     * Keys whose presence means the caller is about to log something it must
     * not. Matched as a substring on purpose: `tokenizer_version` and
     * `broken_count` are refused too, and a harmless key renamed costs less
     * than a secret stored under a harmless-looking one.
     */
    private const REFUSED_KEY = '/(secret|token|password|credential|payload|api[_-]?key|authorization|cookie|private[_-]?key)/i';

    private const MAX_STRING = 190;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, scalar|null|list<scalar|null>>  $before
     * @param  array<string, scalar|null|list<scalar|null>>  $after
     */
    public function record(
        Actor $actor,
        OperatorAuditOperation $operation,
        ?int $connectionId,
        ?int $relatedConnectionId,
        ?string $reviewReference,
        array $before,
        array $after,
        ?\DateTimeInterface $occurredAt = null,
    ): OperatorAudit {
        $tenantId = $this->tenantContext->requireTenantId();

        if ($actor->tenantId !== $tenantId) {
            throw new OperatorAuditException('An operator audit row names an actor inside the current tenant.');
        }

        $occurredAt = $occurredAt === null
            ? \DateTimeImmutable::createFromInterface(now())
            : \DateTimeImmutable::createFromInterface($occurredAt);

        return OperatorAudit::query()->create([
            'tenant_id' => $tenantId,
            'connection_id' => $connectionId,
            'related_connection_id' => $relatedConnectionId,
            'operation' => $operation,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'actor_company_id' => $actor->companyId,
            'review_reference' => $reviewReference === null ? null : trim($reviewReference),
            'before_summary' => $this->summary('before', $before),
            'after_summary' => $this->summary('after', $after),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, scalar|null|list<scalar|null>>
     */
    private function summary(string $side, array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new OperatorAuditException("The {$side} summary must be keyed by names.");
            }

            if (preg_match(self::REFUSED_KEY, $key) === 1) {
                throw new OperatorAuditException("The {$side} summary names [{$key}]; credentials, tokens and payloads never enter the operator audit.");
            }

            // A sub-array is a list of scalars or nothing. A keyed map one level
            // down would carry keys the denylist never saw and array_values()
            // would then discard, storing a credential under the one label that
            // showed what it was (review on #201).
            if (is_array($value) && ! array_is_list($value)) {
                throw new OperatorAuditException("The {$side} summary value for [{$key}] is a keyed map; summaries hold scalars or lists of scalars, never structures.");
            }

            $clean[$key] = is_array($value)
                ? array_map(fn (mixed $item): mixed => $this->scalar($side, $key, $item), $value)
                : $this->scalar($side, $key, $value);
        }

        return $clean;
    }

    private function scalar(string $side, string $key, mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING) {
                throw new OperatorAuditException("The {$side} summary value for [{$key}] is longer than a summary; do not copy contents into the audit.");
            }

            return $value;
        }

        throw new OperatorAuditException("The {$side} summary value for [{$key}] must be a scalar or a list of scalars, not ".get_debug_type($value).'.');
    }
}
