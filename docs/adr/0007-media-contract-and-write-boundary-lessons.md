# ADR 0007: Media Contract And Write Boundary Lessons

## Status

Accepted as a maintenance and release rule for the 0.5.x line.

## Decision

Toolkit owns reusable WordPress ability contracts, schemas, risk metadata, and
host-facing proposal/write boundaries. It does not own Cloud execution,
continuation scheduling, provider state, approval/audit storage, or final
authorization.

Media contract changes must be explicit and consumer-driven. Fields such as
expected_current_media_fingerprint, media_fingerprint,
metadata_fingerprint, transform_facts, replacement lineage,
source_match, and final_write_path require a version note, consumer inventory,
fixtures, and Core/Adapter/Toolbox/Cloud matrix evidence.

Backup retention is a safety contract: records marked
manual_confirmation_required are never removed by automatic cleanup.
Cleanup must be bounded, cursor-based, concurrency-aware, and tested for
missing files, deletion failures, current files, path scope, retention
boundaries, cron lifecycle, and multisite behavior.

## Rejected Alternatives

Do not silently add required fields, use a broad compatibility shim, turn
Toolkit into a workflow runtime, or claim Plugin Check clean while warnings
are merely undocumented. Do not let same-name ability registration silently
retain an older callback/schema.
