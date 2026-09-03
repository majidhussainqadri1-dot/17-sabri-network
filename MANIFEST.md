# Repository Manifest — File 17 v2.1.0

File 17 no longer treats a committed root checksum list as current package truth. The authoritative package-integrity evidence is generated from the exact staged release tree by `sabri-network/tools/package.sh` for the exact evaluated commit.

## Current deterministic package evidence

For an exact repository HEAD, `tools/package.sh`:

1. stages the installable `sabri-network/` tree while excluding repository-only evidence/tests/tools/build output;
2. refuses symbolic links in the staged release tree;
3. normalizes permissions and timestamps;
4. lints staged PHP and syntax-checks every governed JavaScript runtime entry point;
5. verifies required governed release surfaces;
6. generates `sabri-network/MANIFEST.sha256` inside the staged tree from every staged release file;
7. verifies that staged manifest before packaging;
8. copies the exact source manifest to `build/17-sabri-network-and-messages-2.1.0.manifest.sha256`;
9. builds `build/17-sabri-network-and-messages-2.1.0.zip` deterministically; and
10. writes the ZIP SHA-256 to `build/17-sabri-network-and-messages-2.1.0.zip.sha256`.

`tools/quality-check.sh` then runs the package process twice and requires byte-for-byte identical ZIP, ZIP checksum and source-manifest output, extracts the package, compares the embedded manifest with the build manifest, and verifies every packaged digest.

## Installable payload rule

The installable payload is whatever the exact current `tools/package.sh` stages from `sabri-network/` after its explicit exclusions. This rule deliberately avoids maintaining a second hand-edited file list that can drift from the executable packaging truth.

Repository-only files such as `.github/`, review/audit ledgers, tests, tools and generated `build/` output are not installed unless explicitly changed by the package script.

## Evidence boundary

A generated manifest, reproducible ZIP and successful exact-head CI establish repository/package evidence only. They do not establish staging acceptance, deployed-artifact parity, database/schema version, migration execution, live behavior or operational readiness.

**Exact deployed code ابھی unverified ہے؛ repository-based diagnosis provisional ہے۔**