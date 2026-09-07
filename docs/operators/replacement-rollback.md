# Provider replacement rollback

Reverse a reviewed provider replacement (`ProviderReplacementService::remap()`,
#165) while it is still reversible:

```bash
php artisan people-connector:replacement-rollback 123 --tenant=7 --as=42 --review=rollback-2026-09-06
```

`123` is the id of the `provider.identities_remapped` operator audit row the
remap wrote. The command runs as the named operator, needs
`people-connector.identity.manage` (the same capability as the remap), and
exits non-zero on any refusal, so a runbook step cannot mistake "past the
boundary" for "done".

## What is reversible

Every handover the audit names is reversed in one transaction, or none is:

- the old identity on the source connection goes back to `active` with
  `effective_to` and `replaced_by_identity_id` cleared;
- the new identity on the replacement connection becomes `remapped`, pointing
  at the old one. It is never deleted: the history reads as two handovers, not
  as one that never happened;
- the workforce entity ids do not change at any point, so nothing People owns
  is touched;
- one `identity_handed_over` history event is written per identity in the
  reverse direction with provenance `provider.replacement_rollback`, and one
  `provider.identities_remap_rolled_back` operator audit row names the original
  audit id and the external ids on both sides.

## The boundary

The rollback is refused, naming the first identity past the boundary and
writing nothing, when either holds:

1. **The replacement has observed the identity.** The new identity's
   `last_observed_at` is later than the handover, meaning a sync pass on the
   replacement connection has read facts the source never saw. Putting the
   source back would leave it current for a record it is not.
2. **The source connection is retired.** Retirement (#180) is the deliberate
   irreversible step: it freezes the connection's identities, projections and
   checkpoints as history. A replacement whose source has been retired is
   history with it.

An audit that has already been rolled back is refused too, so the same row
cannot be reversed twice. An audit id from another tenant is not found.

## Sequence

1. Replace: `remap()` hands identities over and writes the audit.
2. Verify on the replacement connection (`people-connector:cutover-rehearsal`,
   #184). Do not run a sync pass on it until the mapping is confirmed: the
   first pass that observes a handed-over identity closes the rollback window
   for it.
3. Wrong mapping? Roll back with this command, fix the review sheet, remap.
4. Right mapping? Sync, then retire the source. After that there is no way
   back short of a new replacement in the other direction.
