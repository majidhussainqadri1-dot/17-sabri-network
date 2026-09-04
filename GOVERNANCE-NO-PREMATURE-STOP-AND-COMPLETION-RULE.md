# Sabri Social Homeopathy Platform — No-Premature-Stop & Exact-Completion Governing Rule

**Status:** Binding project governance rule  
**Applies to:** All requested multi-round repository reviews, audits, defect-correction cycles, QA cycles, release-readiness cycles, and similar sequential work in this project.  
**Adopted:** 2026-09-04

## حاکم قانون: نامکمل کام پر خود سے رکنا یا تکمیل ظاہر کرنا ممنوع

جب صارف کسی repository/file پر واضح تعداد میں review rounds، audits، corrections، tests یا دوسرے مسلسل مراحل مکمل کرنے کا حکم دے، تو assistant کے لیے مطلوبہ تمام مراحل مکمل ہونے سے پہلے اپنی طرف سے کام روکنا، درمیانی نتیجہ کو آخری جواب بنانا، یا completion کا تاثر دینا ممنوع ہوگا۔

### 1. Exact Requested Count Rule
اگر صارف N rounds مانگے تو N کے N rounds مکمل کرنا لازم ہوگا۔ M < N پر رکنا completion نہیں سمجھا جائے گا۔

### 2. No Unrequested Mid-Cycle Final Response
صارف نے اگر درمیان کی رپورٹ نہ مانگی ہو تو assistant اپنی طرف سے partial-round status کو final answer میں تبدیل نہیں کرے گا۔ صرف مکمل requested batch کے اختتام پر final completion report دی جائے گی۔

### 3. Mandatory Round Sequence
ہر round میں پہلے پورا review ختم ہوگا؛ دوران review coding fix شروع نہیں ہوگی۔ پھر:

**Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round**

اگلا round صرف اس وقت شروع ہوگا جب موجودہ round کے ثابت شدہ defects درست، regression-tested، اور exact-head CI سے verify ہوچکے ہوں۔

### 4. Completion Claim Gate
`Complete`, `Completed`, `مکمل`, `ختم`, `Resolved`, یا اس مفہوم کا کوئی لفظ صرف اس وقت استعمال ہوگا جب:

- صارف کی مانگی ہوئی پوری round-count مکمل ہو؛
- ہر defect-bearing round کی correction مکمل ہو؛
- regression gates پاس ہوں؛
- required exact-head CI پاس ہو؛
- final reviewed HEAD واضح ہو؛
- اور جہاں live/staging verification درکار ہو وہاں project کے Live-First rules کے مطابق الگ verification status دیا جائے۔

### 5. Technical Blocker Exception — Never Mislabel as Completion
اگر کسی حقیقی tool/permission/system limitation کی وجہ سے باقی requested work اسی وقت جاری رکھنا ممکن نہ ہو، تو assistant صرف blocker report دے گا اور واضح طور پر لکھے گا:

**INCOMPLETE — requested cycle ابھی مکمل نہیں ہوا؛ اسے completion report نہ سمجھا جائے۔**

ایسی حالت میں:
- مکمل کیے گئے exact round/gate کا آخری verified HEAD محفوظ رکھا جائے گا؛
- باقی اگلا exact step درج کیا جائے گا؛
- partial work کو final completion نہیں کہا جائے گا؛
- اور موقع ملتے ہی اسی exact state سے کام جاری کیا جائے گا، ازسرنو یا اندازے سے نہیں۔

### 6. No Self-Imposed Stopping Because of Length, Time, or Convenience
جو کام user نے ایک مسلسل batch کے طور پر دیا ہو، اسے صرف جواب لمبا ہونے، وقت لگنے، intermediate milestone آنے، یا status share کرنے کی خواہش کی وجہ سے نہیں روکا جائے گا۔

### 7. User Interruption Has Priority
اگر user خود `کام روکیں`، scope تبدیل کرے، یا درمیان میں رپورٹ طلب کرے تو وہ نئی صریح ہدایت اس rule پر مقدم ہوگی۔ اس صورت میں موجودہ exact state محفوظ کرکے user instruction کے مطابق عمل ہوگا۔

### 8. Final Report Must State Defect-Bearing Rounds
جب requested batch مکمل ہوجائے تو آخری رپورٹ میں لازماً الگ بتایا جائے گا کہ کن rounds میں defects نکلے، کن rounds میں کوئی نیا defect نہیں نکلا، اور ہر defect-bearing round کا final correction/CI state کیا تھا۔

### 9. Interaction with Existing Governing Rules
یہ قانون درج ذیل موجودہ حاکم اصولوں کے ساتھ بیک وقت لازم ہوگا:

- Evidence-First Exact-HEAD Production Truth Rule
- Root-Cause-First, No-Patch-Stacking Rule
- Live-First Exact-Deployed-State Rule
- Review → Ledger Freeze → Fix → Regression → Exact-head CI → Next Round discipline

ان میں تعارض کی صورت میں زیادہ سخت evidence, safety, live-truth, اور exact-state requirement غالب ہوگی؛ لیکن partial work کو completion کہنا ہر حال میں ممنوع رہے گا۔

## مختصر لازمی یادداشت

**"جتنے rounds مانگے گئے ہیں، اتنے مکمل کیے بغیر نہ رکنا، نہ final completion report دینا۔ اگر حقیقی technical blocker آجائے تو صرف INCOMPLETE blocker report دینا؛ completion کبھی نہیں کہنا۔"**
