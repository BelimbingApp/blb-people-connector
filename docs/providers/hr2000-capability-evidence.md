# HR2000 capability evidence register

**Status:** employee-directory file inspection verified; remaining discovery incomplete — 2026-09-08.
**Scope:** the SBG deployment profile, adapter ID hr2000.sbg.
**Governing plan:** [People 0001](https://github.com/BelimbingApp/blb-people/blob/main/docs/plans/0001-people-architecture-and-provider-boundaries.md).
**Delivery issue:** [connector #133](https://github.com/BelimbingApp/blb-people-connector/issues/133).

## What is established

The [adapter](../../Connector/Providers/Hr2000Adapter.php) declares one
read-only `employee_directory` channel delivered by file exchange through
`ImportsWorkforceFiles`. Its resolved port accepts the exact candidate-1 CSV
fixture for dry-run inspection and refuses projection application. Every
provider-write port returns null, and the other eleven entries in
[PeopleCapability](../../Connector/Enums/PeopleCapability.php) remain
undeclared. This is evidence of the adapter's narrow current surface, not of a
vendor product limitation.

**Verified** requires deployment-specific vendor/customer evidence plus the
applicable supported-interface or fixture proof. **Unverified** means that
evidence has not been supplied. **Unsupported** below means unavailable through
the current adapter; vendor capability remains unverified. The employee CSV
row is verified only for parsing and inspection: it does not authorize applying
rows to projections or writing to HR2000. Generic product literature in the earlier
[discovery contract](../contracts/hr2000-discovery.md) is a discovery lead,
not customer entitlement, interface conformance or processing approval.

## Capability register

The machine-readable form of this table is
[capability-register.json](capability-register.json) (`hr2000.sbg` verifies
`employee_directory` against the candidate-1 fixture and parser test);
`connector:health:check` compares adapter declarations with it.

All rows inherit the current adapter evidence above. The proposed evidence
packages must identify the installed product/version and licensed operation,
not just a similarly named product. A supplied document does not by itself
implement or enable a port.

| Capability | Current state | Evidence needed to verify the SBG operation | Risk if assumed |
|---|---|---|---|
| company_directory | Unsupported — vendor unverified | Vendor-supported company schema/API or file sample; stable keys and customer-confirmed platform-company mapping; sandbox company-isolation proof | Ambiguous company attribution or cross-company exposure |
| employee_directory | Verified — candidate-1 CSV dry-run inspection only ([fixture](../../Connector/Tests/Fixtures/hr2000-employee-sample.csv), [parser proof](../../Connector/Tests/Feature/Hr2000ImportDryRunTest.php), #161/#277) | Projection application remains disabled pending approved mapping, atomic import and tenant/company denial proof | Excessive personnel replication or incorrect deactivation |
| organization_directory | Unsupported — vendor unverified | Supported unit/position schema and identifiers; effective-date and assignment samples; customer-confirmed source ownership | Invented structure or invalid employee placement |
| manager_hierarchy | Unsupported — vendor unverified | Supported reporting relationships with effective dates, acting/multiple assignments and vacancy semantics; sandbox traversal cases | Incorrect reporting scope or inferred access grants |
| user_directory | Unsupported — vendor unverified | Supported user identity schema and reviewed employee/login binding; tenant/company denial proof | Identity collision or impersonation through coincident IDs |
| payroll | Unsupported — vendor unverified | Licensed read/write operations and restricted fields; vendor-supported contract; independent authorization and sandbox proof for each allowed operation | Salary, bank or tax disclosure; unintended payroll mutation |
| attendance | Unsupported — vendor unverified | Declared attendance authority; supported event/correction contract or approved file sample; timezone, replay and acceptance-state proof | Duplicate facts or treating a pending correction as accepted |
| leave | Unsupported — vendor unverified | Licensed query/submission/decision contract, authority and scopes; idempotency, timeout and reconciliation sandbox cases | Competing balances, duplicate requests or false approval |
| claims | Unsupported — vendor unverified | Vendor-supported claim operation/schema and customer-approved scope; sandbox state/error evidence | Unsupported submission or confidential claim exposure |
| training | Unsupported — vendor unverified | Supported history export schema and provenance sample; approved import-only mapping to the selected People installation | A historical import becoming a competing live business writer |
| documents | Unsupported — vendor unverified | Supported document operation and storage contract; per-access authorization, expiry, malware validation and audit proof | Sensitive document leakage or uncontrolled retained copies |
| single_sign_on | Unsupported — vendor unverified | Supported authentication protocol, audience and identity-binding contract; scoped positive/denial sandbox cases | Mistaking authentication for authorization or broad impersonation |

## Discovery checklist and responsibility

“Known” describes repository facts only. “Unknown” identifies deployment
evidence still required. Owners below are proposed responsibility roles for
discovery, not an assertion that named people accepted assignments.

| Discovery item | Known | Unknown / evidence required | Owner |
|---|---|---|---|
| Product edition/version | Profile has product and version fields | Installed edition/version and customer/vendor confirmation | Deployment owner + vendor |
| licensed modules | Profile accepts evidenced module names; undiscovered list is empty | Purchased modules and permitted read/write operations | Deployment owner + vendor |
| hosting | Profile represents hosting mode | SBG topology, operator and administrative separation | Deployment owner |
| API or file formats | Candidate-1 employee CSV inspection is implemented and fixture-proved | Approval and implementation for projection application, exports, remote HTTP or direct database | Vendor + integration owner |
| identifiers | Stable-key mapping evidence is required | Resource keys, company cardinality, transfer/rehire/deactivation semantics | Vendor + data owner |
| authentication | No implemented HR2000 port | Authentication method, credential scopes, delegated actor model and rotation | Vendor + security owner |
| pagination/deltas | No implemented HR2000 port | Cursor/continuation semantics, ordering, replay and deletion behavior | Vendor + integration owner |
| errors | No implemented HR2000 port | Error taxonomy, partial responses, timeout/unknown outcomes and retry rules | Vendor + integration owner |
| rate limits | No deployment limits supplied in profile | Quotas, batch sizes, backoff and operational windows | Vendor + operations owner |
| sandbox | No sandbox evidence supplied in profile | Approved test endpoint/files, test data and denial/conformance results | Deployment owner + vendor |
| support | Vendor support reference is required | Written support entitlement and supported-interface conditions | Deployment owner + vendor |
| data-use rights | Security approval reference is required | Approved processing purpose, field allowlist, retention and rights | Data owner + security owner |
| Timezone and encoding | Both are required profile fields | Confirmed timezone/encoding and representative file cases | Vendor + integration owner |
| Company axis | Unverified or provider-coarser attribution blocks activation | Reviewed mapping preserving platform-company isolation | Data owner + integration owner |

## Activation and authority limits

The [deployment profile](../../Connector/Data/Hr2000DeploymentProfile.php)
blocks incomplete discovery. Even populated profile fields retain
transport_implementation_unavailable once a transport is selected: dry-run
file inspection is not a deployable import transport. Health remains Unknown without a connection attempt.
The [existing tests](../../Connector/Tests/Feature/Hr2000AdapterTest.php)
document these guards; this documentation PR did not rerun them or contact a
vendor/sandbox.

[Transport configuration](../../Connector/Enums/Hr2000Transport.php) rejects
screen scraping and undocumented interfaces. A DirectDatabase enum value does
not authorize access: written vendor/customer support and a later reviewed port
are required, and plan 0001 forbids automating direct writes to a vendor
database. Do not infer leave submission, roster changes, payslip access or
payroll writeback from a generic product description.

Where files are the supported route, retain provenance, approvals and
reconciliation. An exported request is not an accepted HR decision. Skills,
Training and Progression business authority stays with the selected People
installation; outages cannot silently create a connector fallback writer.

Update a row only with linked deployment evidence and the operation-specific
proof. Preserve unsupported versus unavailable, unauthorized, stale and empty
outcomes at the execution boundary. Never put credentials or raw sensitive
payloads in this register.
