# File 17 — Fresh 10-Round Review — Round 7 Frozen Ledger

**Round:** 7  
**Reviewed parent:** `fbd2f339b8922d992a275fc21b0b6304a57cf56c`  
**Scope:** File-17 privacy exporter/eraser override precedence, legal/safety hold guard, Future-24 erasure cursor semantics, message-version retention, Smail erasure progress, presence/transfer erasure, private-byte deletion retry and retained-data truth.  
**Discipline:** the full Round-7 review was completed before any correction began.

## Frozen defects

### R7-D01 — Future message-version erasure can finish with held rows remaining while reporting no retained data

`SN_Sixth_Fresh_Privacy_Hardening::erase_future()` advances a monotonic cursor past held message-version rows. The local `$retained` counter only reflects held rows encountered on the current invocation. If a held version was encountered on an earlier page and the final page contains no held row, the eraser can return `done=true`, `items_retained=false`, and no retained-data message even though held `sn_future_message_versions` rows for that sender still exist.

The retained rows are intentionally lawful, but the completion receipt is therefore not truthful about retained personal/integrity evidence.

**Severity:** Medium privacy-completion / retention-truth defect.

**Required correction:** derive final retained-version truth from remaining committed version rows for the sender (after all deletable rows processed), and include that in `items_retained/messages` without preventing completion of lawfully held evidence. Add permanent regression coverage.

### R7-D02 — Final Smail eraser replaced the bounded canonical eraser with unbounded state/draft mutations

`SN_Fifth_Fresh_Privacy_Hardening::erase_smail()` is the final Smail eraser owner before the global guard, but it deletes every `smail_states` row for the user and updates every live draft in one transaction, then always returns `done=true` on success. This superseded the bounded `SN_Smail_Runtime_Hardening` eraser (`ERASE_BATCH=100`) and can make high-volume accounts repeatedly fail/time out on the same oversized transaction rather than guaranteeing monotonic page-by-page progress.

**Severity:** High privacy-erasure progress / availability regression.

**Required correction:** select and process bounded Smail state/draft IDs per invocation, preserve transactional all-or-nothing semantics for that batch, explicitly test whether more rows remain, and return `done=false` until the domain is exhausted. Add permanent regression coverage.

## Correction gate

Round 8 must not begin until both defects are corrected, regressions are permanent, and exact-head PHP 8.1 + PHP 8.3 CI passes.
