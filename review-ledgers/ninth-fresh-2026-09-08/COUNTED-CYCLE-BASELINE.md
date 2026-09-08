# Counted 10-round cycle baseline

Before the counted cycle, baseline validation exposed two concrete defects: malformed hidden-message/search rows could fail open at the visibility filter, and `ARCHITECTURE.md` still declared runtime 2.0.2 while the candidate runtime is 2.1.0. A subsequent space-moderation inspection also found missing reliable `space.member_unbanned` event emission. Those defects were corrected and regression-protected before this counted cycle begins.

Because the earlier exploratory R02/R03 notes were produced while the documentation mismatch had not yet been corrected, they are not counted toward the stopping-threshold calculation. The counted ten-round cycle starts only from the corrected branch state containing all pre-cycle fixes.

Repository/live evidence boundary is unchanged: this is repository-source work only; deployed code, DB/schema, migration, staging and live status remain separately unverified.
