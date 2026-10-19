# File 17 — Release Manifest and Integrity Authority (runtime 2.1.0)

**Governing scope:** Sabri Network and Messages — File 17.  
**Repository baseline audited:** `8ae656e51796d1f05865d8be5dca2480443d79ca` (main, verified 2026-10-19).  
**Status:** repository candidate; no staging/live/deployed-artifact parity claim.

## Current and authoritative packaging flow

The installable source lives inside `sabri-network/`. The actual, reproducible
2.1.0 package is built by `sabri-network/tools/package.sh`, and the complete
quality/package gate is `sabri-network/tools/quality-check.sh`.
The package script stages the current plugin tree, excludes tests/tools/build,
then generates a **fresh full-tree content manifest** at
`sabri-network/MANIFEST.sha256` within the staged ZIP.

The build outputs are:
- `sabri-network/build/17-sabri-network-and-messages-2.1.0.zip`
- `sabri-network/build/17-sabri-network-and-messages-2.1.0.zip.sha256`
- `sabri-network/build/17-sabri-network-and-messages-2.1.0.manifest.sha256`

For every exact commit, the full quality gate rebuilds the ZIP twice, compares
byte-for-byte reproducibility, validates the source-manifest copy, checks each
archive entry hash and checks the ZIP checksum. The source files are not frozen
to a hand-maintained list in this document: their exact list and hashes must be
taken from the verified immutable-commit artifact.

## Historical record, explicitly not an active checksum authority

The former repository-root `CHECKSUMS.sha256` contained **59** historical entries.
At the audited main HEAD, there were **167** source files within the plugin package
selection and **108** not covered by that old root list. It was an incomplete
and superseded snapshot, **not** evidence of missing files in a successfully
generated current package. That historical snapshot has been retained at
`historical/CHECKSUMS-legacy-pre-2.1.0.sha256`, outside the release tree,
and removed from the repository root to eliminate ambiguous authority.

**Never verify a 2.1.0 ZIP against historical checksums.** Verify the
exact-commit, generated archive and its embedded/source manifests.

## Evidence boundary

The pre-correction main commit
`8ae656e51796d1f05865d8be5dca2480443d79ca` passed PHP 8.1
and PHP 8.3/quality-and-package GitHub Actions at workflow run `36077208413`.
This **does not** automatically prove any subsequent review-branch commit.
A fresh successful run on that exact new head is required. Historical ZIP
artifacts may expire; no current downloadable ZIP is claimed by this file.

Staging install, migration, companion contract acceptance, real providers,
browser/security/load/accessibility evidence, live deployed checksum, DB version
and operational monitoring remain separate, unverified gates.
