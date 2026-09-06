# Workforce webhook trigger

The connector webhook is disabled unless `PEOPLE_CONNECTOR_WEBHOOK_ENABLED=true`.
When enabled, configure `PEOPLE_CONNECTOR_WEBHOOK_SECRETS` as a JSON object keyed
by provider connection id, for example `{"42":"a deployment secret"}`. Keep
the value in the deployment secret store, not in source control.

Send a `POST` to `/webhooks/people-connector/{connectionId}` with:

- `X-People-Connector-Timestamp`: the current Unix timestamp;
- `X-People-Connector-Signature`: `sha256=` followed by the lowercase HMAC-SHA256
  digest; and
- any provider payload as the raw request body.

The signed message is:

```text
{connectionId}\n{timestamp}\n{raw body}
```

The timestamp must be within `PEOPLE_CONNECTOR_WEBHOOK_TOLERANCE_SECONDS` of
the connector clock (default: 300 seconds). A valid request returns `202` and
queues the ordinary incremental workforce pass for that connection. The job
receives only the tenant and connection ids; it never receives or parses the
webhook payload, so projections remain owned by `WorkforceSyncRunner`.
