# File 17 — Next Fresh 10-Round Audit — Round 7 Frozen Ledger

**Round:** 7  
**Reviewed parent:** `7361781d088d4eb2e26d14d7506aab70848df2df`  
**Review discipline:** the call/meeting review was completed before this ledger was frozen; no Round-7 correction was started during review.

## Scope reviewed

Reviewed the call/Meet mutation lock boundary and File-00 refresh (`SN_Call_Runtime_Hardening`), meeting-object authorization/token-delivery checks (`SN_Fourth_Fresh_Call_Hardening`), provider configuration/health/short-lived media credential issuance (`SN_Conference_Provider`), Sabri Meet control-plane route and membership model (`SN_Meet`), and the canonical space/conversation membership synchronization established in Round 6.

## Frozen defect ledger — R7

### R7-D01 — Media/call/meeting locks do not join the canonical space-owner lock for space-backed conversations

`SN_Call_Runtime_Hardening` serializes call/meeting mutations with a call/meeting lock and a `sn:f17:conversation:*` lock, plus a relationship pair lock for direct conversations. For a group/channel conversation owned by a File-17 space, however, membership removal/ban/lifecycle/ownership transitions are serialized under `sn:f17:space:*` and can update the canonical conversation-member row without acquiring the call runtime's conversation advisory lock.

Therefore a media-credential/join/call mutation can prove conversation membership, invoke provider-adjacent work, and still race a canonical space removal/ban because the two owners do not share an advisory lock. The post-provider File-00 refresh does not itself re-establish space membership. This is a cross-owner TOCTOU authorization defect, not merely an implementation-style difference.

**Severity:** High authorization / media-credential race.

**Correction boundary:** whenever a call or meeting mutation resolves a conversation, also resolve whether that conversation belongs to a canonical File-17 space and include that `sn:f17:space:*` owner lock in the sorted lock set. Add a permanent regression proving call/meeting mutation locking joins the space lock before exact-head CI.
