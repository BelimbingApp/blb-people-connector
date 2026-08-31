# BLB People Connector

Provider-neutral People integration hub for [Belimbing](https://github.com/BelimbingApp/belimbing). It lets connector-owned capabilities use a stable workforce contract while the authoritative HR provider can be native `blb-people`, HR2000, or another conforming adapter.

This repository is a nested-git Domain repo. Mount it at `app/Domains/PeopleConnector/` inside a Belimbing checkout:

```bash
git clone https://github.com/BelimbingApp/belimbing
git clone https://github.com/BelimbingApp/blb-people-connector belimbing/app/Domains/PeopleConnector
```

The initial `Connector` Module owns adapter discovery, provider-neutral contracts, capability declarations, health, compatibility, and the deliberately safe disconnected state. Adapters expose only the narrow ports and transports they actually support; page cursors and durable synchronization checkpoints are separate contracts. Connector-owned Skill and Training Modules build on that seam, so provider adapters remain replaceable.

Licensed under MIT, same as the framework.
