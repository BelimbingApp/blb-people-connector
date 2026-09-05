# HR2000 file-exchange protocol

**Status:** required protocol, not an enabled integration — 2026-09-06.  
**Scope:** the SBG deployment profile, adapter ID `hr2000.sbg`.  
**Governing evidence:** [HR2000 capability evidence register](hr2000-capability-evidence.md).  
**Governing plan:** [People 0001](https://github.com/BelimbingApp/blb-people/blob/main/docs/plans/0001-people-architecture-and-provider-boundaries.md).  
**Delivery issue:** [connector #150](https://github.com/BelimbingApp/blb-people-connector/issues/150).

## Current boundary

No HR2000 file format is approved for this deployment. The current adapter
declares zero capabilities and returns no port contracts. No SBG-specific
schema, sample, licensed operation, processing approval or transport
implementation has been supplied. Generic product literature is a discovery
lead only; it does not approve a format or establish that an operation is
available to SBG.

Accordingly, every HR2000 import and export operation remains disabled. This
document defines the protocol that a later file-exchange implementation must
meet after the evidence register verifies the operation. It does not add a
capability, implement a transport, authorize direct database access or make
HR2000 an authority for Skills, Training or Progression workflows.

## What makes a format approved

The capability register must link an evidence package for each operation and
direction before the adapter may publish a file port. The package must identify:

- the installed product edition/version, licensed module and supported
  operation;
- a vendor-supported schema or representative file sample, its schema version,
  encoding and timezone rules;
- the supported delivery channel and written support conditions;
- stable provider identifiers, company cardinality and the reviewed mapping to
  the platform tenant and company;
- the permitted fields, processing purpose, retention, data-use rights and
  security approval;
- representative sandbox or approved test files covering valid input,
  rejection, duplicates, transfer/rehire/deactivation where applicable, and
  tenant/company denial; and
- the data owner, integration owner and deployment owner responsible for the
  approval and operation.

The approved-format list is therefore empty today. A similarly named HR2000
product, a manually produced spreadsheet or a successful ad hoc upload is not
format evidence. Changing a schema, mapping, encoding, timezone, operation,
direction or company scope requires a newly reviewed evidence package; it must
not silently inherit an earlier approval.

## Immutable exchange record

Every received or produced file must be recorded against the provider
connection, tenant and company before processing. The record binds the file
name, lowercase SHA-256 of the exact bytes, approved schema version, operation
and direction, receipt or production time, evidence-package reference and the
responsible actor. The implementation must retain the permitted provenance and
decision evidence for the approved retention period without putting credentials
or raw sensitive payloads in logs or documentation.

The existing [`ProviderFile`](../../Connector/Data/ProviderFile.php) contract
requires a name, path and lowercase SHA-256. Inspection returns the exact hash,
schema version and an unambiguous accepted or rejected result. A rejected
inspection has one or more explanations; an accepted inspection has none.
These are minimum executable invariants, not proof that an HR2000 schema exists.

## Import protocol: HR2000 to Belimbing

An import is allowed only for a verified import operation whose selected
authoritative-writer and field allowlist permit the data. The sequence is:

1. Receive the file through the approved channel into non-authoritative staging.
   Bind it to the intended provider connection, tenant and company, calculate
   its SHA-256, and reject ambiguous scope before reading business rows.
2. Inspect the exact bytes against the approved schema version, encoding,
   timezone, identifiers, field allowlist and company mapping. Produce a dry-run
   result with file-level errors and, where the approved schema supports rows,
   row number, stable rejection code and safe detail.
3. Obtain explicit approval from the assigned data owner or delegated import
   approver. The approval binds the exact SHA-256, schema version, operation,
   tenant/company scope, mapping version and inspection result. Approval of one
   file never approves changed bytes or a replacement file.
4. At execution, use the
   [`ImportsWorkforceFiles`](../../Connector/Contracts/ImportsWorkforceFiles.php)
   boundary to atomically re-inspect the exact file and import only an accepted
   inspection with the same hash. A changed or newly rejected file returns to
   staging and requires a new inspection and approval.
5. Record accepted and rejected counts plus one rejection detail for every
   rejected row, and preserve source/hash/schema provenance on accepted facts.
   Do not treat a missing record in a partial file as a deactivation unless the
   verified contract explicitly supplies complete-snapshot and deactivation
   semantics.

A file-level rejection produces no authoritative import. An accepted file may
contain row rejections only when its verified schema defines that behavior and
the result reports matching accepted/rejected counts and rejection details, as
required by
[`ProviderFileImportResult`](../../Connector/Data/ProviderFileImportResult.php).
There is no local fallback writer when HR2000 or the exchange channel is
unavailable.

## Export protocol: Belimbing to HR2000

An export is allowed only for a separately verified export operation and
approved schema. Producing or delivering a file records a request for the
external authority; it does not record an HR2000 business decision. The request
must remain labelled **exported — not yet accepted by HR2000** until supported
acceptance evidence for the exact file hash and request is reconciled.

The exchange record must preserve the originating actor and authorized company
scope, stable request/idempotency reference, operation, approved schema version,
SHA-256, produced/delivered timestamps and delivery evidence. A transport
acknowledgement proves only what its verified contract says it proves. It must
not be relabelled accepted, approved or rejected merely because the file was
generated, copied, emailed, uploaded, or because delivery timed out.

If acceptance cannot be established, the outcome stays open for reconciliation.
Retry is permitted only when the verified HR2000 contract provides a safe
idempotency or lookup rule; otherwise an operator must resolve the unknown
outcome before another request is sent. Direct writes to an HR2000 database and
screen scraping remain prohibited paths.

## Rejection and reconciliation

Reconciliation is part of the exchange, not a spreadsheet maintained outside
the audit trail. The operator must be able to trace an issue to the connection,
tenant/company, operation, schema version, exact hash, inspection/import result,
approval, external reference when supplied, and first/latest observation.

| Situation | Required result |
|---|---|
| File fails inspection | Import nothing; record safe file-level explanations and keep the file rejected. |
| Accepted file has rejected rows | Preserve matching accepted/rejected counts and row/code/detail entries; do not hide rejected rows behind a successful batch state. |
| File changes after inspection or approval | Reject execution; calculate the new hash and require a new inspection and approval. |
| Export is delivered but acceptance is not proved | Keep **exported — not yet accepted by HR2000** and open an operator-visible reconciliation item. |
| Delivery or processing outcome is unknown | Do not infer failure or retry blindly; use only a verified lookup/idempotency mechanism, otherwise require operator resolution. |
| Corrected replacement is supplied | Record it as a new file and hash, link it to the rejected exchange, inspect and approve it independently, then record the resolution without rewriting the original evidence. |
| Company or identity mapping is ambiguous | Fail closed and reconcile the mapping under scoped authority; never guess a tenant, company or employee identity. |

The connector already has tenant-scoped reconciliation records and review
services, but no HR2000 file transport is implemented. A later implementation
must integrate these protocol outcomes with that operator workflow and prove
retention, authorization, reopen/resolution and denial behavior. This document
does not claim that integration exists.

## Activation checklist

Before enabling any HR2000 file operation, all of the following must be true:

- its capability-register row is verified with deployment-specific evidence;
- the adapter publishes only the supported direction and file port;
- the approved schema and company/identity mappings pass positive, rejection
  and tenant/company denial tests using approved representative files;
- exact-hash inspection, approval binding, atomic reinspection/import,
  provenance and reconciliation behavior are executable and tested;
- exported requests cannot appear accepted without the verified external
  acceptance evidence; and
- unsupported operations remain undeclared and are rejected at the execution
  boundary.

Until then, the current zero-capability adapter is the correct fail-closed
behavior.
