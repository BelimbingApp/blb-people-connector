# Connector diagnostic privacy

**Scope:** Connector issue #139. Inventory at connector base 2a824e8; provider integration only.
**Policy source:** People plan 0001, no credential exposure or raw sensitive payload logs. The existing [DataShare contract](company-ownership.md) still governs operator-chosen transfer redaction.

## Serialization boundaries

| Boundary | What crosses it | Protection and evidence |
| --- | --- | --- |
| ProviderConnectionStore / ProviderConnection.public_metadata | Typed mode, HTTPS origin and Base Integration record IDs | ProviderConnectionMetadata rejects credentials, paths, query and fragments without echoing the input; no credential field is accepted by this DTO |
| ProviderCredentialRecord array/JSON | Rotation and scope metadata | secret_reference is hidden; the persisted attribute remains available to credential handling |
| ProviderCredentialRecord.toCredential / ProviderCredential.publicClaims | Typed credential ID, key ID, audience, scope and lifecycle metadata | DTO has no secret-reference field; public claims use an explicit field list; ProviderAccessSecurityTest checks it |
| ProviderHealthMonitor → ProviderHealthStore → Connections page | Health state, checked-at and last-successful-sync timestamps | Discard adapter-returned free text before caching; caught failures use a connector-owned generic message |
| Existing health cache | Previously cached health may contain raw adapter text | A v2 key prevents pre-fix cache entries from reaching the UI; the next refresh repopulates status and timestamps |
| SyncWorkforceCommand console output | Connection ID and typed sync report, or a generic failure | Never interpolate an adapter exception message; preserve nonzero exit and tenant cleanup |
| WorkforceSyncRunner → ReconciliationIssueStore.details | Reason code, field/count diagnostics and related external IDs | ReconciliationIssueDetails has five named fields; no generic response-body slot. Identifier validation refuses raw JSON without echoing it. Conflict handling maps exception classes to reason codes rather than persisting getMessage() |
| Reconciliation Livewire validation/audit | Connector-owned validation messages, operator notes and opaque review/identity references | ReconciliationReviewService's handled InvalidReconciliationIssueException messages are fixed text; no adapter exception is caught and forwarded here. Operator-entered notes and business IDs are not a generic secret-scrubbing interface |
| WorkforceProjectionStore / WorkforceHistory / snapshots and history events | Deliberately typed workforce business records and provenance | These are authorized workforce data, not raw HTTP responses or diagnostic logs. Typed names, IDs and contact fields remain business data; they are not scanned for accidental user-entered secrets |
| DataShare transfer of connector tables | Selected database rows, including a stored opaque credential reference | Model hidden fields do not govern query-builder exports. Unredacted transfers remain faithful copies; operator-selected secret_reference redaction emits null and warns that the credential row becomes unrestorable |

No Connector production Log::, logger() or framework report(exception) call was found. The command's console failure is the logging-adjacent output that needed a fix. ProviderException still carries adapter message/context/previous exception in memory: it is not a safe public serialization format. The inventory covers shipped Connector boundaries; it does not certify arbitrary future adapters, application-wide exception handlers, infrastructure logs or provider-side logging.

## Operator-visible behavior

Health responses expose status and timestamps, not adapter-authored prose. Failed health checks retain the connector's generic failure guidance. A deployment begins with unknown health until a fresh check because pre-fix entries cannot be trusted. The cache-key change invalidates reads; it does not claim to erase old backend cache entries.

A failed synchronization identifies its connection, tells the operator to check provider availability and configuration, exits unsuccessfully and clears tenant context. Raw messages and response bodies are not copied into console output as diagnostics. This change introduces no logging sink or diagnostic payload storage.

Exact backups and diagnostic output serve different contracts. The reference is opaque Base Integration indirection, not key material. Requiring all transfers to redact it would reverse the owner's DataShare decision and break faithful restoration; issue #139's blanket wording must be read with that exception. Do not silently turn suggested redaction checkboxes on.

## Regression evidence

ProviderDiagnosticPrivacyTest exercises model array/JSON, five credential-bearing endpoint shapes, two raw reconciliation identifier fields, all four provider health states, thrown health errors, thrown sync errors and a pre-fix cached message. ConnectionsPageTest and ProviderHealthTenancyTest retain state/timestamp and tenant-isolation checks while expecting adapter prose to be absent.

The existing DataShareRoundTripTest remains the package-level authority for selected redaction and unredacted restoration. Deleting the selected-redaction handoff fails its null-value assertion. No new duplicate export test or default-export policy is introduced.
