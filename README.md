# BLB People Connector

Provider-neutral People integration hub for [Belimbing](https://github.com/BelimbingApp/belimbing). It lets connector-owned capabilities use a stable workforce contract while the authoritative HR provider can be native `blb-people`, HR2000, or another conforming adapter.

This repository is a nested-git Domain repo. Mount it at `app/Domains/PeopleConnector/` inside a Belimbing checkout:

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-people-connector belimbing/app/Domains/PeopleConnector
```

The initial `Connector` Module owns adapter discovery, provider-neutral contracts, capability declarations, health, compatibility, and the deliberately safe disconnected state. Adapters expose only the narrow ports and transports they actually support; channel direction is derived from readable/writable port markers, and feature code resolves ports through `ProviderPortResolver` so an undeclared read or write fails before the adapter is invoked. File imports bind inspection to the exact hash, and page cursors are separate from durable synchronization checkpoints. Connector-owned Skill and Training Modules build on that seam, so provider adapters remain replaceable.

Connector data sits on two boundaries, not one. Every table is tenant-owned, and most of them are also owned by one company inside that tenant. [`docs/contracts/company-ownership.md`](docs/contracts/company-ownership.md) classifies every table, explains the two different things called "company" here, and describes the guard that makes omitting the company axis raise an error rather than quietly return a sibling company's rows.

Licensed under MIT, same as the framework.
