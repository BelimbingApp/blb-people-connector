# Platform drift dispatch

The connector keeps its twice-daily scheduled CI as a backstop, but GitHub may
disable schedules after 60 days without repository activity. The durable
signal is therefore emitted by the platform after its own `main` CI succeeds.

The platform sends `POST /repos/BelimbingApp/blb-people-connector/dispatches`
with this contract:

```json
{
  "event_type": "belimbing-platform-main-ci-succeeded",
  "client_payload": {
    "platform_repository": "BelimbingApp/belimbing",
    "platform_ref": "refs/heads/main",
    "platform_sha": "<40-character lowercase commit SHA>",
    "platform_run_url": "https://github.com/BelimbingApp/belimbing/actions/runs/<run-id>"
  }
}
```

`.github/workflows/platform-drift.yml` rejects any other repository, ref, SHA
shape, or run-URL shape. It then composes this connector against
`client_payload.platform_sha`, so the receiving run proves the exact platform
revision that emitted the event rather than whichever revision `main` reaches
later.

The current platform sender expects an owner-managed fine-grained personal
access token in the secret `PEOPLE_CONNECTOR_DISPATCH_TOKEN`. Select only
`BelimbingApp/blb-people-connector` and grant only repository `Contents:
write`, which GitHub requires for the repository-dispatch endpoint. Do not
reuse a human's general-purpose merge token. A missing or rejected credential
must fail the platform sender visibly rather than silently skipping the drift
check.

A GitHub App can replace that token only when the sender mints a short-lived
installation token on every run from owner-managed App credentials. Do not
store an installation token directly in `PEOPLE_CONNECTOR_DISPATCH_TOKEN` as
though it were durable; installation tokens expire.

The connector workflow must exist on its default branch before the platform
sender is enabled. Until both halves are merged and the secret is installed,
the scheduled trigger remains the only automatic platform-drift backstop.
