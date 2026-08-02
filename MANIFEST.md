# Repository Manifest — File 17 v2.0.0

`CHECKSUMS.sha256` is the canonical integrity manifest for the exact installable package source tree. The quality gate proves that its file list equals the files selected by `tools/package.sh`, verifies every digest, then rebuilds the ZIP twice and compares the outputs byte-for-byte. Tests, tools and repository evidence documents are deliberately excluded from the installable package.

## Installable payload — checksum governed

```text
sabri-network/ARCHITECTURE.md
sabri-network/CHANGELOG.md
sabri-network/INSTALLATION-URDU.txt
sabri-network/README.md
sabri-network/SECURITY.md
sabri-network/SYSTEM-STATUS.txt
sabri-network/UPGRADE-URDU.txt
sabri-network/assets/css/meet.css
sabri-network/assets/css/message-search.css
sabri-network/assets/css/messages.css
sabri-network/assets/css/network.css
sabri-network/assets/js/meet.js
sabri-network/assets/js/message-search.js
sabri-network/assets/js/messages.js
sabri-network/assets/js/network.js
sabri-network/assets/network-default-avatar.svg
sabri-network/includes/class-sn-activator.php
sabri-network/includes/class-sn-admin.php
sabri-network/includes/class-sn-ajax.php
sabri-network/includes/class-sn-auth.php
sabri-network/includes/class-sn-db.php
sabri-network/includes/class-sn-meet.php
sabri-network/includes/class-sn-message-integrity.php
sabri-network/includes/class-sn-message-search.php
sabri-network/includes/class-sn-messages.php
sabri-network/includes/class-sn-outbox.php
sabri-network/includes/class-sn-policy.php
sabri-network/includes/class-sn-privacy.php
sabri-network/includes/class-sn-private-files.php
sabri-network/includes/class-sn-relationships.php
sabri-network/includes/class-sn-rest.php
sabri-network/includes/class-sn-safety.php
sabri-network/includes/class-sn-shortcode.php
sabri-network/readme.txt
sabri-network/sabri-network.php
sabri-network/templates/communication-settings.php
sabri-network/templates/meet-app.php
sabri-network/templates/messages-app.php
sabri-network/templates/messages-standalone.php
sabri-network/templates/network-app.php
sabri-network/templates/network-standalone.php
```

## Current repository-only quality and evidence additions

```text
.github/workflows/quality.yml
CHECKSUMS.sha256
CODING-COMPLETENESS.md
MANIFEST.md
MESSAGE-SEARCH-OUTBOX-REPORT.md
MESSAGES-SURFACES-RECEIPTS-REPORT.md
REVIEW-REPORT.md
SAFETY-HARDENING-REPORT.md
STATUS.md
sabri-network/tests/*
sabri-network/tools/package.sh
sabri-network/tools/quality-check.sh
```

Generated output under `sabri-network/build/` is never committed and is not part of the source manifest.
