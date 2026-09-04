# people-connector

**Status:** In progress
**Last Updated:** 2026-08-31
**Sources:** BelimbingApp/blb-people#20–#38; BelimbingApp/belimbing#457; `SBTG_Skill_Management_System.xlsx`; Belimbing module, tenancy, database, authz, integration, and UI contracts
**Agents:** codex/gpt-5, codex/gpt-5.6-sol

## Problem Essence

Belimbing must support native and third-party HR providers without leaking provider-specific records into supplemental People workflows. SBG also needs a governed Skill and Training system that remains authoritative when HR2000 does not supply the required matrix or lifecycle.

## Desired Outcome

An installable PeopleConnector Domain supplies one provider-neutral workforce seam and connector-owned Skill and Training Modules. Provider replacement, outage, and remote/co-located placement do not rewrite supplemental history or create a second live writer.

## Top-Level Components

- `Connector` owns provider contracts, adapter discovery, health, capability truth, compatibility, workforce projections, and reconciliation.
- `Skill` owns catalogues, requirements, assessments, development actions, current-score projection, coverage, and employee skill register.
- `Training` owns requests, approvals, events, participants, evaluations, effectiveness reviews, evidence, and training history.
- Provider adapters translate `blb-people`, HR2000, or another supported source behind the Connector contract.

## Design Decisions

Three ownership shapes were considered. Extending `blb-people` directly would be simple for native installations but would make supplemental capabilities disappear when HR2000 replaces it. Splitting a People server and client would encode one deployment topology in the product model and still would not describe third-party capability gaps. A separate optional PeopleConnector Domain wins: it avoids mount collisions when both Domains are installed, keeps supplements available with HR2000, and makes the projection boundary explicit rather than permitting foreign keys to provider-owned employee tables.

Connector records use tenant-owned internal IDs plus stable provider references and captured organization snapshots. Mutable names and email addresses are never identity keys. Remote and co-located adapters implement the same contract and expose unsupported operations honestly.

Keeping Skill and Training in one Module would reduce initial wiring but couple assessment truth to training-delivery workflow and permissions. Separate Modules cost one explicit collaboration seam and better preserve their different invariants. They collaborate through narrow contracts; neither reads the other's tables directly.

`lockForUpdate()` / `lock: true` in Connector stores is the PostgreSQL serialisation path. On SQLite those locks compile to no-ops; production concurrency there is WAL plus busy timeout with a single transaction attempt — see `docs/contracts/store-concurrency.md` (#12).

## Public Contract

Adapters publish stable identity, contract and adapter versions, independently composable capability channels, health/freshness, paginated bootstrap data, durable incremental checkpoints, and only the narrow operation ports they actually support. Channel direction is derived from readable/writable port markers rather than trusted metadata; feature code uses `ProviderPortResolver` so undeclared operations fail before adapter invocation and a declared-but-missing port is reported as incompatibility. Adapter port resolution is an internal seam separate from the public `ProviderAdapter` contract, and requires short-lived evidence bound to the provider, tenant, company scope, permission, capability, direction, and contract; a registry lookup cannot be used to reach a port without those live checks. Final file imports atomically re-inspect the exact hash. Snapshot/file-only providers are not required to fake incremental or reconciliation operations. Provider failures, including rejected input, use structured domain exceptions. All non-LLM remote HTTP transport passes through Base Integration.

### Ownership of the access records

- `people_connector_connector_provider_credentials` is Class D: its company boundary follows `connection_id`, and every read/write resolves the tenant-owned connection before using the credential.
- `people_connector_connector_privileged_support_grants` is Class T: it carries an optional tenant company scope and requires both actors to validate inside that scope.
- `people_connector_connector_privileged_support_actions` follows its grant through `(grant_id, tenant_id)` and is append-only at both the model and database layers.

## Phases

### Foundation and providers

- [x] Publish provider-neutral DTOs, capability/error vocabulary, registry, conformance helper, disconnected UI, and CI. {codex/gpt-5}
- [ ] Merge the connector ownership/privacy boundary and `0330` migration allocation proposed in BelimbingApp/belimbing#457.
- [x] Persist non-secret tenant/company-scoped connections, canonical workforce identities, typed projections, append-only provenance, durable sync checkpoints, and reconciliation issues. {codex/gpt-5.6-sol}
- [x] Add the connector-side actor/scope authorization gate, short-lived rotatable credential references, revocation, credential-free provider UI hand-offs, and time-boxed two-person support grants with immutable action records. {desktop-luna}
- [ ] Add the provider-neutral user projection, explicit freshness/stale decisions, and adapter-driven bootstrap/incremental execution over the persistence foundation.
- [ ] Prove governed export, backup/restore, privacy-aware deletion/tombstones, and full synchronization recovery for connector-owned records.
- [ ] Add authentication/SSO and privileged-access controls without exposing secrets.
- [ ] Implement and certify `blb-people` and HR2000 adapters.

This persistence foundation is only a partial delivery of [1004]/[1006]. It does not yet provide a typed user projection, provider sync execution, freshness policy, export/backup/restore, privacy deletion, or any Skill/Training aggregate; those remain explicit work below.

### Skill core

- [ ] Implement proficiency catalogues and versioned position requirements.
- [ ] Implement evidence-backed immutable assessments and batch matrix.
- [ ] Implement owned development actions and reassessment/current-score projection.
- [ ] Implement coverage, dashboards, workbook import/export, and automation.

### Training lifecycle

- [ ] Implement catalogues, sessions, and the event-level training register.
- [ ] Implement requests, HOD/HR/approver decisions, and budgets.
- [ ] Implement participant attendance, results, certificates, evidence, and due dates.
- [ ] Implement participant evaluations and HR follow-up.
- [ ] Implement 30/60/90-day effectiveness review linked to verified reassessment.
- [ ] Implement employee skill register, passport, print/export, and stale-provider disclosure.

### Adoption and release

- [ ] Build dry-run, idempotent migration tooling with provenance and reconciliation.
- [ ] Inventory and map SBG's legacy portal data and HR2000 export capability.
- [ ] Pilot Production, Engineering, QAC/R&D, Planning, and IT with HR/HOD sign-off.
- [ ] Prove privacy, recovery, performance, support, cutover, rollback, and retirement runbooks.
