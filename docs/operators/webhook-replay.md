# Webhook delivery replay

Every verified provider callback is recorded as a webhook delivery: the
tenant and connection it arrived for, the provider's delivery id, and the
fate of the incremental sync pass it triggered (`accepted` while queued,
`delivered` when the pass completed, `failed` when its last attempt threw).
A failure is kept as a reason code (`page_corrupt`, `sync_refused`,
`provider_refused`, `provider_unavailable`, `answer_lost`, `provider_failed`,
`unexpected`) and the exception class, never the exception message, which
can carry provider response fragments or credentials
(docs/contracts/diagnostic-privacy.md). The provider's payload is never
stored: a callback is a trigger, not a second projection-write path.

The queue retries a failing pass on its own. After a fix, re-send one
specific delivery as a named operator:

```bash
php artisan connector:webhook:replay 1234 --tenant=7 --as=42
```

The replay is a new delivery row that names the original in
`replayed_from_id`, so the original's failure stays as evidence, and one
operator audit row (**Webhook delivery replayed**) names the operator, the
connection, both delivery ids and the failure reason code. The audit
summary carries no exception text and no payload.

The command refuses, exits non-zero and sends nothing when the delivery is
not in the acting operator's tenant, when it is not `failed` (an already
delivered or still queued delivery is not replayed), when its connection is
no longer active, or when the operator is outside the tenant or lacks
`people-connector.connection.manage`.

`--dry-run` prints what would be sent (delivery, provider delivery id,
status, attempts, failure reason and class, tenant, connection, job and
queue) and neither
dispatches nor records anything.

Bulk replay and retry policy are out of scope; see the reconciliation
runbook for record-level refusals.
