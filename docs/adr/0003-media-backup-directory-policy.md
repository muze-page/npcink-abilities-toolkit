# ADR 0003: Store Media Operation Backups in a Dedicated Uploads Directory

## Status

Accepted

## Date

2026-06-04

## Context

Media replacement, Cloud derivative adoption, and main-file rename operations
preserve backup files for rollback. Early development builds wrote those backup
files beside the current attachment file in the same year/month uploads
directory. That kept path construction simple, but it mixed operational backup
artifacts with normal media assets and exposed backup filenames in the same
listing surface as user-facing uploads.

The project is still pre-user, so there is no production backup history to
migrate. We can change the forward-looking storage policy without preserving a
same-directory compatibility path for newly created backups.

## Decision

Store newly created Npcink AI media operation backups under:

```text
wp-content/uploads/npcink-abilities-toolkit-backups/{current-upload-subdirectory}/
```

For example, a current file at:

```text
2026/06/hero.webp
```

will produce a backup such as:

```text
npcink-abilities-toolkit-backups/2026/06/hero-npcink-abilities-toolkit-backup-media_replace_20260604_081417_ab12cd34.webp
```

The backup filename still includes the original stem, operation-specific backup
suffix, replacement or rename id, and extension. Rollback metadata continues to
store the concrete uploads-relative backup file path. No schema migration is
introduced for development-era same-directory backups.

## Alternatives Considered

### Keep backups beside current media files

- Pros: Minimal code and obvious locality.
- Cons: Pollutes normal media month directories and makes backup artifacts look
  like public content assets.
- Rejected: The project is pre-user, so this cost is avoidable now.

### Use a hidden directory under uploads

- Pros: Keeps backups out of casual directory views.
- Cons: Dot-directory behavior varies across hosts, web servers, backup tools,
  CDN rules, and WordPress media tooling.
- Rejected: A named directory is easier to audit and support.

### Add automatic backup TTL deletion now

- Pros: Limits disk growth while retaining a bounded recovery window.
- Cons: A restore is no longer possible after expiry.
- Decision: Accepted by ADR-0006. Cleanup is maintenance-only, targets only the
  dedicated backup directory, preserves history summaries, and never removes
  the current attachment.

## Consequences

- New backup files no longer appear in the same year/month media directory as
  the current attachment.
- Restore and rollback continue to work through recorded relative file paths.
- Future cleanup can target `npcink-abilities-toolkit-backups/` explicitly without scanning all
  media month directories for backup suffixes.
- Future public capabilities can expose backup listing and restore planning
  without changing this storage base. Manual deletion remains intentionally out
  of scope.
