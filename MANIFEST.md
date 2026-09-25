# Repository Manifest — File 17 v2.1.0

The current package-integrity authority is the **generated manifest emitted for the exact deterministic 2.1.0 build**, not a long-lived static checksum file committed at repository root.

For an exact immutable commit that passes the PHP 8.3 full-quality/package job, `sabri-network/tools/package.sh` produces:

- `sabri-network/build/17-sabri-network-and-messages-2.1.0.zip`
- `sabri-network/build/17-sabri-network-and-messages-2.1.0.zip.sha256`
- `sabri-network/build/17-sabri-network-and-messages-2.1.0.manifest.sha256`

The generated manifest is created from every staged installable file after the package selection is made, verified with `sha256sum -c`, copied outside the staged tree as build evidence, embedded as `sabri-network/MANIFEST.sha256` inside the ZIP, and verified again after extraction. The quality gate then builds the ZIP twice and requires byte-for-byte identical output.

## Package selection truth

The installable payload is selected by `sabri-network/tools/package.sh`. Repository-only paths such as `.git`, `.github`, `build`, `tests`, `tools` and the inner historical `REVIEW-REPORT.md` are excluded. Current packaged runtime/evidence surfaces are explicitly required by the package gate, including current README/changelog/security/install/upgrade/system-status files, `CURRENT-CANDIDATE-BOUNDARY.txt`, `REVIEW-CYCLE-ID.txt`, `QA-INVENTORY.txt`, `NO-LIVE-CLAIM.txt`, canonical runtime hardening classes, templates and governed JavaScript/CSS assets.

An old static file named `CHECKSUMS.sha256` is intentionally not retained as current repository truth because its digests would become stale whenever the candidate changes. Exact package checksum evidence must come from the generated artifact attached to the exact successful workflow commit.

## Current review boundary

Current repository candidate: File 17 v2.1.0, **seventh fresh 20-round sequential review cycle**. Historical manifests, ledgers, package hashes and CI runs remain evidence only for their own immutable commits.

Repository/package integrity does not establish staging acceptance, deployed-version parity, live DB/schema version, live migration state, live deployment or operational acceptance.

Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔
