# Media Format Attention Boundary

## Status

Accepted for the current media governance path.

## Context

`format_attention` is not ordinary media metadata. It can involve image
dimensions, compression, format conversion, derivative generation, file
replacement, backup, rollback, cache behavior, and attachment metadata
regeneration. Those concerns are heavier than `npcink-abilities-toolkit/update-media-details`,
which only updates reviewable media text and source metadata.

Mapping `format_attention` directly to a write action inside
`npcink-abilities-toolkit/build-media-inventory-fix-plan` would blur the boundary between a
read-only inventory plan and file-asset mutation.

## Decision

Keep `format_attention` in `manual_review` for now.

The media inventory fix plan may include read-only diagnostics such as:

- detected format reasons, for example `legacy_image_format`, `image_too_wide`,
  or `large_file`;
- suggested operation labels such as `generate_optimized_derivative`,
  `compress`, or `consider_format_conversion`;
- a future target ability hint such as
  `npcink-abilities-toolkit/build-media-derivative-cloud-request`;
- explicit `write_action_generated=false`;
- high-risk classification for file-asset work.

It must not map `format_attention` to `npcink-abilities-toolkit/update-media-details`, because
format work is not metadata-only.

It must not map `format_attention` directly to asset write abilities from the
read-only fix plan. Hosts may use the diagnostic output to start a separate
approval flow.

## Current Abilities-Side Cloud Handoff

`npcink-abilities-toolkit/build-media-derivative-cloud-request` is the local abilities-side
handoff for Cloud derivative processing. It is intentionally read-only:

- it inspects the attachment and builds a bounded
  `generate_optimized_media_derivative` request contract;
- it does not upload the source file;
- it does not call Cloud;
- it does not include credentials, Authorization headers, signed headers,
  callback URLs, or presigned source URLs;
- it does not write WordPress media records or attachment metadata.

The request contract can include optional image watermarks by artifact reference
or text watermarks such as `AI`. Text watermarks are still Cloud-worker
instructions only; the local abilities package normalizes the bounded text,
position, opacity, font size, color, background, and margin fields, but does not
render watermark pixels.

The Cloud addon or host transport layer owns upload, signing, and dispatch. The
Cloud worker owns derivative generation. The local WordPress host owns final
approval, recording, replacement, rollback, and metadata writes.

## Derivative Artifact Integrity Contract

Planning and adoption share one fail-closed, exact 11-field artifact descriptor.
It requires a canonical `art_[0-9a-f]{32}` id, future expiry, supported and
internally consistent MIME/format pair, positive bounded dimensions, a byte
count no greater than 25 MiB, lowercase SHA-256, a local filename suggestion,
the fixed WordPress-final filename basis, and an explicit bounded warnings
array. Each image axis is limited to 8192 pixels and decoded area is limited to
16,777,216 pixels. Missing fields, undeclared fields, arbitrary URLs, run ids,
and legacy checksum aliases fail closed.

Final commit calls only
`npcink_cloud_addon_receive_media_derivative_artifact()`. The Addon returns an
exact 10-field payload: artifact id, raw contents, MIME, width, height, byte
size, SHA-256, unchanged proposal expiry, verified-transfer evidence, and the exact
Cloud delivery ACK projection. Toolkit independently recomputes byte length and
SHA-256, uses the local image inspector to verify decode/MIME/dimensions, and
checks every received fact against the approved descriptor. Received expiry
and `delivery_ack.artifact_expires_at` must equal the original local11 proposal
expiry; acknowledging receipt never creates a shorter retention window.
Timestamps are strict UTC RFC3339. ACK time must be no later than its deadline,
and the unchanged artifact expiry must remain after the ACK.

The ACK is `verified_transfer_only`; it is never local apply evidence. Toolkit
writes only after every transfer check passes, re-verifies the local file, and
then enters the existing Core-governed replacement path. If file creation,
backup, attachment replacement, metadata persistence, reference repair, or
final verification fails, the batch restores the original pointer, MIME,
attachment metadata, affected post content, derivative metadata, and existing
backup history, then removes new derivative/backup/generated files. Toolkit
rechecks attachment pointer, bytes, metadata, MIME, storage, and reviewed post
references after receive and local materialization but before any WordPress
write. Drift returns `409` and removes only the derivative exclusively created
by the losing batch; it never restores over the concurrent winner. Derivative
and backup creation use no-overwrite semantics, and compensation deletes only
files whose recorded filesystem identity still belongs to the batch. The batch
also records exact before and batch-after values for pointer, attachment
metadata, history/latest projections, MIME, and repaired post content. MIME and
post-content writes and rollback use a short `SELECT ... FOR UPDATE` transaction,
PHP strict comparison, and `wp_update_post()` so case-only drift cannot be lost
and the WordPress revision/hook/modified-time/cache lifecycle still runs. A
concurrent logical value or unconfirmed transaction rollback stops all manifest
deletion and returns a bounded `409` conflict. Toolkit does not download
arbitrary artifact URLs; the Cloud Addon owns signed pull and ACK transport
while WordPress remains final review/write/restore truth.

## Remote Object Storage Preflight

Media inspection and derivative request planning expose a `storage` evidence
block so hosts can distinguish ordinary local uploads from attachments whose
public URL or file availability is controlled by an object-storage plugin such
as OSS/CDN offload.

The default Toolkit implementation is conservative:

- local readable uploads can continue through the existing local derivative
  and governed replacement path;
- attachments whose URL no longer matches the uploads base URL, or whose local
  file is unavailable, are marked as `remote_object_storage`;
- remote storage writes default to `blocked` with
  `remote_storage_requires_adapter` or
  `remote_storage_write_requires_adapter`;
- no OSS credentials, bucket names, signed headers, or provider SDK calls are
  stored or emitted by Toolkit.

Hosts may implement `npcink_abilities_toolkit_media_storage_inspection` to
declare a bounded storage adapter, read mode, write mode, restore mode, and
cache-purge requirement. Final file mutation still requires Core approval and
the existing write abilities; this preflight only prevents local-only media
operations from pretending that remote storage is safely writable.
The host-side shim contract is documented in
[OSS Storage Compatibility Shim](oss-storage-compatibility-shim.md).

## Current Backup Storage Policy

Media operation backups are stored under `wp-content/uploads/npcink-abilities-toolkit-backups/`
while preserving the current upload subdirectory under that base. For example,
an attachment main file in `2026/06/` receives backups in
`npcink-abilities-toolkit-backups/2026/06/`.

This keeps operational rollback artifacts out of normal public month media
directories without relying on host-specific hidden directory behavior. Rollback
metadata stores the concrete uploads-relative backup path, so recovery should
read from recorded history rather than reconstructing a path from the current
filename. The decision is recorded in
[ADR 0003](adr/0003-media-backup-directory-policy.md).

## Future Ability Shape

If file-asset work becomes necessary, prefer separate abilities with narrow
contracts:

- `npcink-abilities-toolkit/optimize-media-asset`: first candidate for resize/compress or
  optimized derivative generation. Keep original assets preserved.
- `npcink-abilities-toolkit/convert-media-format`: format conversion such as WebP or AVIF.
  This should start as derivative generation, not direct replacement.
- `npcink-abilities-toolkit/replace-media-file`: highest-risk path for switching an
  attachment file to an approved derivative. Require backup, approval, and
  strict preview. The write preview and commit should include exact
  post-content media URL repairs for the old main file and known intermediate
  sizes.
- `npcink-abilities-toolkit/list-media-backups`: read-only backup history discovery for one
  attachment before any restore proposal is prepared.
- `npcink-abilities-toolkit/restore-media-backup`: host-approved restore path that copies a
  recorded backup back to the original attachment path, restoring the original
  public media URL. Commit uses two optimistic-concurrency gates: one before
  creating the current-file rollback backup and one immediately after that
  exclusive backup copy. Both gates recheck attachment, metadata, MIME,
  storage, reviewed post references, the selected backup bytes, and the restore
  target bytes. The WordPress pointer, metadata, MIME, content repairs, and
  history/latest projections then commit through one bounded CAS mutation
  manifest. When overwrite is explicitly approved, Toolkit first creates an
  exclusive transient copy of the existing target; any later failure restores
  those exact bytes only while the target still matches this batch's after
  snapshot. Successful restore retains the new current-file rollback backup,
  marks the selected history record rolled back, appends the active restore
  record, and removes only its transient overwrite compensation copy. Failed
  restore compensates every completed WordPress and filesystem mutation; a
  concurrent winner produces a bounded conflict instead of being overwritten.
- `npcink-abilities-toolkit/adopt-cloud-media-derivative`: approved local adoption path for a
  non-expired Cloud derivative artifact. Require artifact evidence, backup,
  rollback metadata, host approval before commit, and synchronized
  post-content repairs for old main/intermediate-size uploads URLs that embed
  the attachment.

Recommended rollout order:

1. Read-only inspection and inventory diagnostics.
2. Dry-run derivative preview that does not replace the original.
3. Host-approved commit for derivative generation.
4. Separate replacement and restore flows only after backup and cache behavior
   are documented.

## Guardrails

- Keep `build-media-inventory-fix-plan` proposal-only.
- Keep `format_attention` as manual review, not a write action.
- Do not use `update-media-details` for resize, compression, conversion, or
  file replacement.
- Do not replace original files by default.
- Store media operation backups in the dedicated Npcink AI uploads backup
  directory, not beside normal month-directory media assets.
- Do not introduce queue, cache, CDN, or rollback ownership into the read-only
  inventory path.
- Treat actual file mutation as high risk and host-approved.
- Do not submit or adopt Cloud derivative artifacts without exact SHA-256,
  size, dimension, MIME, and expiry evidence.
