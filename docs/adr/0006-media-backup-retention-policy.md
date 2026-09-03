# ADR 0006: Automatic Retention for Media Operation Backups

## Status

Superseded in part by the batch exception below

## Context

Single-image replacement and restore need a recoverable local safety net, but a
permanent backup archive or a separate backup-management UI would add storage
and operational complexity for a low-frequency operator action.

## Decision

Keep operation backups in the existing dedicated
`npcink-abilities-toolkit-backups/` uploads directory and schedule one daily
maintenance action, `npcink_abilities_toolkit_cleanup_media_backups`.

The default retention is 30 days. Hosts that need a longer window may use the
`npcink_abilities_toolkit_media_backup_retention_days` filter, bounded to
1–365 days (90 days is the recommended longer deployment choice). Cleanup
scans all attachment history in bounded pages, targets only records whose
backup path is inside the dedicated backup directory, and removes only expired
backup files. It keeps the history record, marks it `backup_expired`, and sets
`backup.file_exists=false` plus `backup.expired_at_gmt`.

The current attachment file is never a cleanup target. Missing files are
treated as already removed and are still marked expired. Cleanup is not an
ability, queue, workflow runtime, approval path, or second backup registry.

Exact-manifest media optimization batches are an explicit exception. Their
replacement and restore history records use
`backup_cleanup_policy=manual_confirmation_required`; the daily maintenance
action must leave those backup files intact after the 30-day recovery window.
Expiry changes the product guidance to recommend cleanup, but deletion requires
a separate, explicit operator confirmation. Records without that policy retain
the original automatic-after-retention behavior.

## Consequences

- Legacy and non-batch operation disk usage remains bounded without adding an
  operator-facing backup manager.
- Restore remains available during the retention window and fails closed after
  expiry for automatically managed backups.
- Exact-manifest batch backups remain restorable after the displayed recovery
  window until a future explicit cleanup action is confirmed.
- Historical evidence remains inspectable after the bytes are removed.
- A future settings surface can project 30/90-day policy without changing the
  storage or execution boundary.
