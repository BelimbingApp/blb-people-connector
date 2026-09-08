# People deployment topologies

Belimbing supports three People deployment shapes. They differ in placement
and transport, but not in authority: for each tenant, company scope and
capability there is exactly one declared writer. Installing a module or
registering an adapter does not make it authoritative.

## Supported shapes

| Topology | Modules and placement | Active adapter and transport | Authoritative writers | Required denial proof |
| --- | --- | --- | --- | --- |
| Co-located native People | One Belimbing installation contains `people/provider`, the selected People business modules (including `people/skills` and `people/training` when enabled), `people-connector/connector`, and `people-connector/first-party-people`. | The first-party module registers the native `blb-people` adapter, which uses the in-process People workforce resolver. Connector deliberately preserves that resolver when People is the selected provider; it does not answer a co-located request from an empty projection. | The selected native workforce source writes employment, organisation, manager and position facts. The local People Skills and Training modules write their own catalogues, workflows and history. Connector writes only integration identities, connection state, projections, checkpoints and reconciliation records. | Run the same tenant, company, employee-binding, capability and record-scope refusals through the native seam. The native/projection refusal vocabulary and parity scenarios are pinned by [connector #127](https://github.com/BelimbingApp/blb-people-connector/issues/127). |
| Remote native People | The general installation contains `people-connector/connector`, its required `people/provider` contract module, and only the business modules explicitly assigned to that installation. A separately administered native People installation contains each native workforce or People business module assigned to it. Do not install the same business writer on both hosts. | A native People adapter uses an approved authenticated remote transport. The transport is not yet a generally available capability; until its authentication, audience, freshness, retry and reconciliation contract is implemented, this topology is not activation-ready. | The remote native host writes every capability assigned to it. A business module intentionally assigned to the general installation remains the sole writer for its records. The general installation may retain allowlisted projections and request/reconciliation state, never a fallback workforce ledger. | Repeat the co-located denial matrix at the authoritative remote endpoint, including current actor binding, tenant/company scope and operation/record authorization. Prove audience-bound short-lived delegation, replay/idempotency, unknown-outcome reconciliation and revocation; transport success alone is not authorization. |
| Third-party HR2000 | The Belimbing installation contains `people-connector/connector`, the `people/provider` contract module it requires, and selected People business modules for capabilities HR2000 does not author. HR2000 remains outside the BLB module graph. | The `hr2000.sbg` adapter stays fail-closed. Activate only a vendor-supported, approved file or remote protocol whose edition, licensed modules, identifiers, authentication and operations have evidence. Direct vendor-database writes and screen scraping are not approved transports. | HR2000 writes only the workforce capabilities verified and selected for it. People Skills and Training remain the sole writers for their records unless a later, explicit capability decision assigns another authority. Connector writes integration and reconciliation records only. An export or submitted request is not an accepted HR2000 result. | Apply the remote denial matrix and the credential/diagnostic boundary proven by [connector #140](https://github.com/BelimbingApp/blb-people-connector/pull/140). Add provider-specific capability, company-mapping, delegated-actor, stale-data, replay and reconciliation tests before enabling each operation; unsupported operations remain disabled. |

## No duplicate authority

These rules apply to all three shapes:

1. Provider selection is explicit per capability and scope. An outage, stale
   projection or unsupported operation never activates a local fallback
   writer.
2. A projection is an allowlisted, timestamped view with stable provider
   identity and provenance. It is not an HR ledger and cannot accept an
   authoritative business command unless the selected provider contract says
   it can.
3. Skills, requirements, assessments, training and participation belong to
   the selected People Skills or Training installation. Connector must not
   recreate those modules or their tables.
4. Remote commands are authorized again on the authoritative side. Login,
   network reachability, a connector registry entry or a scheduler credential
   cannot substitute for employee binding, tenant/company attribution,
   capability and record authorization.
5. A timeout after delivery is an unknown outcome. Reconcile the original
   idempotency key; do not retry blindly or switch writers.

## Package and discovery enforcement

The module manifests express load-time dependency direction, not business
authority:

- `people-connector/connector` requires `core/company` and
  `people/provider`. This lets Connector consume the provider-neutral seam; it
  does not make Connector the workforce or People-business writer.
- `people-connector/first-party-people` requires
  `people-connector/connector` and `people/provider`; it is the module that
  registers the co-located `blb-people` adapter.
- `people/skills` requires `people/provider`.
- `people/training` requires `people/provider` and `people/skills`.

Discovery must resolve those stable module IDs and their compatible versions.
It must also refuse two modules that register the same route method/URI or
create the same table. Platform [#570](https://github.com/BelimbingApp/belimbing/pull/570)
provides that collision refusal, so duplicate writers fail during composition
instead of silently shadowing one another. Connector R4 removed its former
Skill and Training copies; the composed domain-pin proof is recorded in
[platform #574](https://github.com/BelimbingApp/belimbing/pull/574).

Manifest dependencies, collision checks and a successful boot are necessary
installation evidence. Activation still requires the topology-specific
authority declaration and denial proof above.

## Reference configuration: remote native People

The remote native shape above is not activation-ready, and the connector now
says so at every point where a caller could mistake a co-located answer for a
remote one. Configuring a connection for it is supported; relying on it to
synchronise is not.

**Placement.** A connection carries `mode`, `remote_base_url` and
`remote_credential_id`. `mode` defaults to `in_process`, so every existing
connection keeps its meaning without a data migration. Only a connection whose
mode is `remote_http` may carry a base URL or a credential reference; setting
either on any other mode is refused rather than silently dropped, because an
operator who typed a base URL and saw no error would reasonably believe the
placement had been configured.

**Allowlist.** `people-connector.remote.allowed_hosts` is empty by default and
must name the host of every remote People installation before a connection can
reference it. This is deployment configuration, not a per-connection field, so
adding a host is a decision with a named owner rather than something an
operator can do from a form. The allowlist is compared against the parsed host
of the URL, so `https://allowed.example@attacker.example/api` is refused: the
host there is `attacker.example`. The base URL must be absolute and `https`.

**Credential scoping.** `remote_credential_id` must name a credential belonging
to that same connection and tenant; a sibling connection's credential is
refused at configuration time, where the operator can still be told which
connection owns it. At probe time the credential is vended by
`ProviderCredentialStore::requireUsable()`, which enforces expiry, revocation
and the tenant boundary in one place. Provider credentials expire within five
minutes of issuance by contract, so the stored reference records the operator's
binding while the store remains the runtime authority.

**Health.** `RemoteProviderHealthProbe` answers for a `remote_http` connection,
and `ConnectionHealthChecker` and `connector:doctor` never ask the in-process
adapter about one. That is the point: `FirstPartyPeopleAdapter::health()`
returns healthy by construction, so a co-located answer about a remote host
describes the wrong machine. States are `Unavailable` when the host is
unreachable, times out or answers non-2xx; `Degraded` when it answers but the
contract majors disagree or its reported watermark is older than
`people-connector.sync.max_age_minutes`; `Healthy` otherwise. Reports carry
reason codes and the host, never transport exception text
(`docs/contracts/diagnostic-privacy.md`).

**What is still missing, and why sync refuses.** Three pieces of the remote
transport do not exist yet:

1. No route serves the health read. Connector #163 shipped it as an in-process
   operator service and recorded that giving it a URL is a separate decision
   with its own exposure to argue about. `people-connector.remote.health_path`
   is therefore configuration rather than a constant, and a `remote_http`
   connection reports `Unavailable` until the remote host serves that path.
2. `ProviderCredential` carries identifiers and never a secret, deliberately,
   so a credential can be logged and reported without leaking one. The
   secret-bearing exchange an authenticated remote call needs is not built.
3. There is no remote bootstrap or change transport at all.

Because of (3), `WorkforceSyncRunner` refuses a pass on a `remote_http`
connection with `ProviderTemporaryException`, before any port is resolved and
before any page is read, and records a `sync:remote:transport-missing` issue for
the operator. Reading its pages through the co-located adapter would fill the
projections from the wrong host and stamp them fresh, which is precisely the
fallback workforce ledger this document forbids. The refusal is temporary
rather than permanent: the placement is legitimate and the transport is what is
absent, so a caller that retries once the transport lands is right to.

**Upgrade order.** Upgrade the remote People host first, then the connector. The
probe reports `Degraded` on a contract-major mismatch and names both majors, so
a half-finished upgrade is visible as a pairing problem rather than an outage.
Upgrading the connector first makes every remote connection degraded until the
People host catches up.
