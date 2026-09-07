# File 17 — Fresh 20-Round Review — R05 Ledger Freeze

Branch: `review/file17-fresh-20-round-2026-09-07`
Reviewed parent: `9ae4ebcd6396f04ab4b1847acc8f7f9ba4775851`
Scope: private attachments/files, encrypted file transfer, scanner/quarantine, chunk/finalize/revoke/download lifecycle, storage containment, byte cleanup/retry, privacy cleanup, and schema-owner interactions exposed by the transfer runtime.

## Frozen findings

### R05-D01 — Local schema-version drift can still trigger unsynchronized module installers after the central migration governor has returned success
Several active module init paths retain `maybe_upgrade() -> install()` behavior (including File Transfer, Messages, Smail, Meet, Two-Plan and Future Superset). The central governor's verified fast path proves physical schema but does not prove all installer-local version options. If a local version option is stale/missing while physical schema and `sn_plugin_version` are current, the governor can return success and the later module callback can run `dbDelta`/version publication outside the global migration lock. That contradicts the established single serialized schema/version authority and re-opens concurrent installer/version-truth races.

Required correction: central migration success must include installer-local version-option truth. Any mismatch must force the governed locked installer path so later module `maybe_upgrade()` callbacks are no-ops rather than independent schema writers.

### R05-D02 — Transfer initiation and chunk acceptance do not revalidate relationship/consent state at the mutation point
`SN_File_Transfer::initiate()` resolves recipients before `START TRANSACTION`, then inserts the session/recipient ledger without rechecking the relationship after transaction start. `upload_chunk()` similarly calls `revalidate()` before encrypted-byte creation and transaction start, but inside the transaction it only locks the transfer session; it does not rerun sender/recipient membership, block, verification and transfer-policy checks immediately before committing the chunk ledger/counter mutation. A concurrent block, membership removal, suspension or consent/policy change can therefore race the accepted transfer/chunk mutation.

Required correction: retain existing preliminary checks for fast rejection, but rerun authoritative `revalidate()` after the session is locked and before the first durable transfer/chunk mutation. Initiation must re-resolve/revalidate the approved recipient set inside its serialized transaction before insert.

## Other reviewed controls
Finalization revalidates under the locked session; malware scanning is fail-closed to quarantine; download grants are version-bound and download revalidates current access; revocation is transactional and commit-reconciled; private attachment bytes are authorization-revoked before unlink and durable retry is extended by the final privacy hardening layer.

## Freeze
Review R05 is complete. No R05 correction was started before this ledger was frozen.
