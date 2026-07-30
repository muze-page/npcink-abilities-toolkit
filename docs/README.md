# Documentation Guide

Use this page as the entry point for integration and debugging work. The
repository contains detailed contract and release notes; this guide points each
reader to the smallest useful path first.

Cross-project platform coordination starts from
`/Users/muze/gitee/npcink-workflow-toolbox/docs/platform/README.md`. This
repository owns Toolkit ability contracts and reusable static workflow
definitions, but it should not become the platform coordination source of
truth.

## Plugin Authors

Use this path when another WordPress plugin wants to provide abilities through
`npcink-abilities-toolkit`.

1. Start with [Provider Plugin Guide](third-party-plugin-guide.md).
2. Copy from the working demo provider in
   [examples/demo-plugin/npcink-abilities-toolkit-demo.php](../examples/demo-plugin/npcink-abilities-toolkit-demo.php).
3. If your plugin or host adapts object-storage-backed media, read
   [OSS Storage Compatibility Shim](oss-storage-compatibility-shim.md).
4. Check the stable public helper surface in [Public API](public-api.md).
5. Verify provider compatibility with:

```bash
composer check:provider-demo
```

Provider plugins should call the public helper functions only. Do not require
files under `includes/` or instantiate classes in the
`Npcink_Abilities_Toolkit` namespace.

## Host And Product Consumers

Use this path when a host product discovers, filters, runs, or projects
abilities.

1. Read the project ownership boundary in the root [README](../README.md).
2. Choose a deployment shape in [Host Profiles](host-profiles.md).
3. Use [Permission Matrix](permission-matrix.md) for write and destructive
   capability expectations.
4. Use [Core Governance Handoff Guide](core-governance-handoff-guide.md) when
   preparing proposal payloads for a governed host.
5. Use [Npcink AI Integration Contract](npcink-ai-integration.md) only when the
   consuming host is Npcink AI or a compatible projection consumer.

Hosts own caller identity, approval, audit, quota, model routing, MCP transport,
and final write authorization. This package owns ability registration, schema
normalization, metadata, and dry-run contract shape.

## REST And External Clients

Use this path when a client needs to discover or run abilities through the
WordPress REST API.

1. Start with [REST Client Quickstart](rest-client-quickstart.md).
2. Use the admin page endpoint values from
   `Tools -> AI Ability Set -> Developer Access` or
   `Npcink AI -> AI Ability Set -> Developer Access`.
3. Use [Troubleshooting](troubleshooting.md) for common 401, 403, missing route,
   missing ability, and dry-run issues.

REST clients should discover the catalog first, inspect each ability's schema,
risk metadata, and permission requirements, then run only abilities that the
current WordPress user is allowed to execute.

Host runtimes that need a cheap compatibility check before fetching the full
catalog can also read `GET /wp-json/npcink-abilities-toolkit/v1/contract` with
a `manage_options` REST caller for plugin version, contract versions, catalog
hashes, workflow hash, and the host-governed write boundary.

## Maintainers

Use this path when changing this package.

Start with [System Audit And Closeout Standard](system-audit-and-closeout-standard.md)
when reviewing repository health, hardening gates, publishing a maintenance
change, or deciding whether a development stage is complete.

1. Read [Next Stage Operating Standard](next-stage-operating-standard.md) for
   the current freeze/observe rules before opening ability work.
2. Check [Host Proof Status](host-proof-status.md) before treating a candidate
   as a Toolkit-owned gap.
3. Run the lightweight local gates listed in [Security And Governance Gates](security-and-governance-gates.md).
4. Use [Testing Strategy](testing-strategy.md) to decide when to add contract,
   smoke, performance, or E2E coverage.
5. Use [Pattern Page Reference Spike](pattern-page-reference-spike.md) before
   changing `build-pattern-page-plan`, page patterns, or Gutenberg-native page
   composition.
6. Use [Block Theme Context Snapshot Proof](block-theme-context-snapshot-proof.md)
   before changing block theme route context, homepage template planning, or
   template proposal fail-closed behavior.
7. Run the real-site smoke from [Local WP-CLI Smoke Test](local-wpcli-smoke.md)
   when a WordPress site is available.
8. Run [Official Stack E2E Environment](official-stack-e2e.md) before changing
   official Abilities API, MCP, or AI plugin compatibility assumptions.
9. Use [Ability Metadata Reference Notes - 2026-07](ability-metadata-reference-notes-2026-07.md)
   before turning official WordPress AI or Abilities API inspiration into
   Toolkit contract changes.
10. Use [Ability Contract Reuse Readiness - 2026-07-08](ability-contract-reuse-readiness-2026-07-08.md)
    before adding new ability functionality for the Core/Adapter/Product reuse
    chain.
11. Use [WordPress Core Ability Convergence](wordpress-core-ability-convergence.md)
    and its [machine-readable overlap manifest](wordpress-core-ability-convergence.json)
    before adding or deprecating generic WordPress entity abilities.
12. Read [ADR 0005](adr/0005-own-reusable-workflow-definitions-and-converge-with-core.md)
    for reusable workflow-definition ownership and Core convergence rules.
13. Use [Structural Split Plan](structural-split-plan.md) before extracting
    oversized package methods, definition providers, or test suites.
14. Use [GitHub Publication And Continuous Gates](github-publication-and-continuous-gates.md)
    for the public repository handoff, CI baseline, and post-publication gate
    status.
15. Read [Performance/Security Hardening Closeout](performance-security-hardening-closeout-2026-06-19.md)
    for the PR #57 merged hardening scope, validation evidence, and no-release
    decision.
16. Use [WordPress.org 0.5.2 Post-Publication Closeout](wordpress-org-0.5.2-post-publication-closeout-2026-06-22.md)
    for the admin-surface release, SVN publication, icon refresh, and Chinese
    Stable Readme translation status.
17. Use [WordPress.org zh_CN Translation Status](wordpress-org-zh-cn-translation-status-2026-07-03.md)
    for the current submitted-but-waiting GlotPress state and the PTE request
    handoff text.
18. Record release evidence in the relevant release verification document.

Prefer adding focused docs to this guide instead of expanding the root README
with every workflow detail.
