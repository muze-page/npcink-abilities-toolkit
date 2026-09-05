# Media Ability Contract Evolution Standard v1

## Before Changing A Contract

1. Identify whether the change is a reusable WordPress contract gap or merely
   a host/product convenience.
2. Inventory every consumer and the exact source SHA used by each one.
3. Decide whether the field is additive, required, renamed, or semantic.
4. Add v3 fixtures and denial/round-trip tests before changing release notes.
5. Run the cross-repository matrix and record accepted versus candidate status.

## Write Boundary

Abilities may discover, validate, preview, or build a proposal. Final writes
remain host-governed and must carry the expected-current fingerprint,
replacement lineage, source match, and explicit native-editor commit posture
when applicable. No ability may smuggle raw database fields, private content,
attachment filesystem paths, credentials, or provider logs into a public
payload.

## Release Proof

For every release, record current assertion counts rather than copying an old
verification note. Run source/static/security/performance gates, minimum and
current WordPress/PHP smoke, packaged Plugin Check, exact-SHA CI, matrix,
package SHA-256, and manual WordPress proof where available. Classify
non-blocking warnings and preserve known M4/Docker/runtime blockers.

During freeze/observe, add no new ability unless a failed consumer proof
identifies a small Toolkit-owned contract gap. Prefer a corrected contract,
permission denial, replay fixture, or bounded performance fix.
