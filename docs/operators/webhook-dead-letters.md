# Webhook delivery dead letters

Each provider connection stores the retry budget captured by newly accepted
webhook deliveries: a maximum attempt count and one backoff delay between each
attempt. The safe default is three attempts at 60 and 300 seconds. Changing a
connection affects new deliveries; already queued jobs retain the policy under
which they were accepted.

After the final failed attempt the delivery becomes `dead_lettered` and the
queue job fails permanently instead of retrying again. An authorized operator
can list only the current tenant's dead letters:

```bash
php artisan connector:webhook:dead-letters --tenant=7 --as=42
```

Once the underlying cause is fixed, replay every listed row through the same
audited replay path as `connector:webhook:replay`:

```bash
php artisan connector:webhook:dead-letters --tenant=7 --as=42 --replay
```

Replay creates a new accepted delivery and preserves the dead-lettered row as
evidence. Provider payload bytes are never retained or re-sent.
