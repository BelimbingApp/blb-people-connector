# BLB People Connector

**Status:** 2026-09-06 — integration-only ownership under [People plan 0001](https://github.com/BelimbingApp/blb-people/blob/main/docs/plans/0001-people-architecture-and-provider-boundaries.md); relocation complete at [R4 merge c3c5b01](https://github.com/BelimbingApp/blb-people-connector/commit/c3c5b0126ded2da39d5b7f035508171a0c61bf3f).

Provider-neutral People integration hub for [Belimbing](https://github.com/BelimbingApp/belimbing). It lets People business modules use a stable workforce contract while the authoritative HR provider can be native `blb-people`, HR2000, or another conforming adapter.

This repository is a nested-git Domain repo. Mount it at `app/Domains/PeopleConnector/` inside a Belimbing checkout:

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-people-connector belimbing/app/Domains/PeopleConnector
```

The initial `Connector` Module owns adapter discovery, provider-neutral contracts, capability declarations, health, compatibility, and the deliberately safe disconnected state. Adapters expose only the narrow ports and transports they actually support; channel direction is derived from readable/writable port markers, and feature code resolves ports through `ProviderPortResolver` so an undeclared read or write fails before the adapter is invoked. File imports bind inspection to the exact hash, and page cursors are separate from durable synchronization checkpoints. The connector owns integration identities, workforce projections, checkpoints, reconciliation issues and provider connections.

Skills, Training and Progression business records belong to the selected People installation. The source modules moved to [People #113 (R2)](https://github.com/BelimbingApp/blb-people/issues/113) and [People #114 (R3)](https://github.com/BelimbingApp/blb-people/issues/114), then [R4 removed them](https://github.com/BelimbingApp/blb-people-connector/commit/c3c5b0126ded2da39d5b7f035508171a0c61bf3f). This repository ships only the Connector integration module and FirstPartyPeople adapter. Provider replacement and outages must preserve People business history without switching to a connector fallback writer.

Connector data sits on two boundaries, not one. Every table is tenant-owned, and most of them are also owned by one company inside that tenant. [`docs/contracts/company-ownership.md`](docs/contracts/company-ownership.md) preserves the source table classifications, explains the two different things called "company" here, and describes the guard that makes omitting the company axis raise an error rather than quietly return a sibling company's rows.

Licensed under MIT, same as the framework.
