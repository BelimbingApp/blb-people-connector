# HR2000 discovery and activation boundary

The HR2000 adapter ID is `hr2000.sbg`. It is an SBG deployment profile, not a
claim that every HR2000 installation exposes the same integration surface.

## What public evidence establishes

HR2000's official product page describes hosted and on-premise product
families and names payroll, staff, time-management, and e-Office products.
Official Quick Staff material describes CSV report export. These are generic
product facts only:

- <https://www.hr2000.com.my/product.htm>
- <https://www.hr2000.com.my/downloads/writeup.qstaff.pdf>
- <https://www.hr2000.com.my/downloads/manual.qpay.pdf>
- <https://www.hr2000.com.my/downloads/sla.html>

They do not prove which product, version, modules, company model, identifiers,
fields, or transport SBG owns. They also do not grant data-processing or write
authority.

## Evidence required before activation

The deployment owner and vendor must sign off the installed product/version,
hosting mode, licensed modules, supported transport, support reference,
stable-key and field mapping, company cardinality, timezone, encoding,
change/deletion/re-hire semantics, operational limits, test path, and
data-processing approval. Credentials belong in the existing connector secret
store and never in `Hr2000DeploymentProfile`.

One workforce company may map to at most one platform company. A provider axis
coarser than SBG's platform-company boundary is ambiguous and fails closed;
discovery must identify a finer supported provider axis instead.

## Current executable surface

No SBG-specific API, file schema, webhook, SFTP channel, or database contract
has been supplied. The adapter therefore declares zero capabilities, resolves
zero ports, performs no health call, and refuses activation even if generic
profile fields are populated. Screen scraping and undocumented interfaces are
explicitly rejected. Direct database access requires written vendor/customer
support and a later reviewed port implementation.

HR2000 training history, if later verified, is import-only provenance.
Connector-owned training requests, evaluations, effectiveness reviews, and
skill scores remain connector-owned live records.
