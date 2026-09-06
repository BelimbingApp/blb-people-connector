# Workforce sync run reports

Open a connection's audit page and choose **Workforce sync pass** rows to inspect bootstrap, incremental and replay activity. The **Stream** field matches an exact stream name; clear it to show all connection activity. The existing connection permission, company access and tenant boundary still apply.

Each authorized pass writes one append-only audit row, including a pass that throws after it starts. The summary contains only the stream, pass kind, pages processed, successful upserts, deactivations, record refusals, elapsed milliseconds and whether the pass returned a report. The ordinary audit envelope retains its actor and connection attribution. Provider payloads, external record IDs, page cursors and exception text are not copied into the summary.

`completed` means the runner returned a report, not that every record was accepted or the checkpoint advanced. A fully refused feed can return a report; replay intentionally does not advance a checkpoint. `refusals` counts record conflicts handled by the runner, while a transport or page-integrity exception is represented by `completed: false` and the partial processed counts. Consult the reconciliation queue for its refusal reason. `pages` counts pages that passed integrity validation; a corrupt page is not counted as processed. Duration uses the monotonic clock and covers the pass after connection and port authorization, excluding the audit write itself.

Requests refused before connection and port authorization do not start a sync pass and create no sync report. A failed pass can already have applied idempotent records; the audit is evidence of those partial counts, not a rollback or a replay command.
