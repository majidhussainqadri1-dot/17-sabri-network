# Repository Manifest — File 17 v2.0.0

`CHECKSUMS.sha256` is the canonical integrity manifest for the **installable package source tree**. The quality gate first proves that its file list exactly matches the files selected by `tools/package.sh`, and then verifies every recorded SHA-256 digest. Repository-only workflows, tests and evidence documents are intentionally outside the installable ZIP and outside that checksum set.

## Installable payload — checksum governed

```text
sabri-network/ARCHITECTURE.md
sabri-network/CHANGELOG.md
sabri-network/INSTALLATION-URDU.txt
sabri-network/README.md
sabri-network/SECURITY.md
sabri-network/SYSTEM-STATUS.txt
sabri-network/UPGRADE-URDU.txt
sabri-network/assets/css/network.css
sabri-network/assets/js/network.js
sabri-network/assets/network-default-avatar.svg
sabri-network/includes/class-sn-activator.php
sabri-network/includes/class-sn-admin.php
sabri-network/includes/class-sn-ajax.php
sabri-network/includes/class-sn-auth.php
sabri-network/includes/class-sn-db.php
sabri-network/includes/class-sn-policy.php
sabri-network/includes/class-sn-privacy.php
sabri-network/includes/class-sn-private-files.php
sabri-network/includes/class-sn-relationships.php
sabri-network/includes/class-sn-rest.php
sabri-network/includes/class-sn-safety.php
sabri-network/includes/class-sn-shortcode.php
sabri-network/readme.txt
sabri-network/sabri-network.php
sabri-network/templates/network-app.php
sabri-network/templates/network-standalone.php
```

## Repository-only quality and evidence files

```text
.github/workflows/quality.yml
.gitignore
CHECKSUMS.sha256
MANIFEST.md
README.md
REVIEW-REPORT.md
SAFETY-HARDENING-REPORT.md
STATUS.md
sabri-network/tests/adversarial-contracts.php
sabri-network/tests/package-adversarial-contracts.php
sabri-network/tests/package-static-contracts.php
sabri-network/tests/realtime-adversarial-contracts.php
sabri-network/tests/realtime-static-contracts.php
sabri-network/tests/relationships-adversarial-contracts.php
sabri-network/tests/relationships-runtime-contracts.php
sabri-network/tests/relationships-static-contracts.php
sabri-network/tests/forensic-review-3-adversarial-contracts.php
sabri-network/tests/forensic-review-3-static-contracts.php
sabri-network/tests/rate-limit-runtime-contracts.php
sabri-network/tests/retention-lock-empty-runtime-contracts.php
sabri-network/tests/safety-adversarial-contracts.php
sabri-network/tests/safety-runtime-contracts.php
sabri-network/tests/safety-static-contracts.php
sabri-network/tests/static-contracts.php
sabri-network/tools/package.sh
sabri-network/tools/quality-check.sh
```

Generated build output under `sabri-network/build/` is never committed or included in this manifest.
