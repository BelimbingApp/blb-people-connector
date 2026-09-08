# Feed page checksum

**Status:** contract, implemented in `Connector/Support/WorkforcePageChecksum` and enforced by `Connector/Services/WorkforceSyncRunner` (issue #204).
**Source:** BelimbingApp/blb-people plan 0001: raw payload retention must not evade the projection allowlist; adapter paging must not contradict itself.

## Rule

An adapter may declare a checksum on any page it returns (`WorkforcePage::$checksum`, `WorkforceChangePage::$checksum`). When it does, the sync runner recomputes the fingerprint from the typed records it received and, if the two differ, refuses the page **before projecting any record of it**, raises one `sync_page_corrupt` reconciliation issue (severity `error`, key `sync:page:corrupt:<pass>:<cursor>`, details `field: checksum`, `reason_code: checksum_mismatch`), and stops the pass with `CorruptWorkforcePageException`. The checkpoint does not advance; the next pass asks for the same page again.

A page with no declared checksum is processed as before. A declared value that is not a lowercase hex SHA-256 is refused by the page constructor.

## What the issue carries

The issue names the pass and the page cursor. It carries no record, identifier, name or hash of the content: the page was not trusted, so nothing from it is retained.

## Canonical fingerprint

`WorkforcePageChecksum::of($page)`: every record (bootstrap: companies, organisation units, positions, employees, in that order) or change (incremental: in feed order) becomes a map of its public properties in declaration order, recursively; nested references and records the same way; instants as UTC RFC 3339 with milliseconds; enums as their value. The maps are JSON-encoded with slashes and unicode unescaped and hashed with SHA-256, lowercase hex. Cursors, `asOf` and `complete` are not part of the fingerprint: a page is the same page however it was reached.

Adapters compute the same value from the records they are about to return, through the same class, so a declared checksum is a promise about the typed content, not about bytes on a wire.
