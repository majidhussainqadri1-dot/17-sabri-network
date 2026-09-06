from pathlib import Path

ROOT=Path('.')
current='review/file17-another-10-round-2026-09-05'
old='review/file17-next-10-round-2026-09-04'
all_rounds='R1, R2, R3, R4, R5, R6, R7, R8, R9, R10'
old_rounds='R1, R2, R3, R4, R6, R7, R8, R9, R10'
old_rounds_and='R1, R2, R3, R4, R6, R7, R8, R9 and R10'
all_rounds_and='R1, R2, R3, R4, R5, R6, R7, R8, R9 and R10'

docs=[
    Path('README.md'), Path('STATUS.md'), Path('CODING-COMPLETENESS.md'),
    Path('sabri-network/readme.txt'), Path('sabri-network/CHANGELOG.md'),
    Path('sabri-network/CURRENT-CANDIDATE-BOUNDARY.txt'), Path('sabri-network/QA-INVENTORY.txt'),
]
for p in docs:
    s=p.read_text(encoding='utf-8')
    s=s.replace(old,current)
    s=s.replace(old_rounds,all_rounds).replace(old_rounds_and,all_rounds_and)
    s=s.replace('54 PHP review suites','57 PHP review suites').replace('54 PHP review suite','57 PHP review suite')
    s=s.replace('54-suite','57-suite').replace('54 suites','57 suites').replace('**54**','**57**')
    s=s.replace('Current next-fresh 10-round corrective cycle','Current another-fresh 10-round corrective cycle')
    s=s.replace('Latest next-fresh 10-round review cycle','Latest another-fresh 10-round review cycle')
    s=s.replace('Next-fresh 10-round corrective cycle','Another-fresh 10-round corrective cycle')
    s=s.replace('next-fresh 10-round','another-fresh 10-round')
    s=s.replace('4–5 September 2026','5–6 September 2026').replace('2026-09-04 to 2026-09-05','2026-09-05 to 2026-09-06')
    s=s.replace('Current assessment date:** 5 September 2026','Current assessment date:** 6 September 2026')
    s=s.replace('- Clean rounds: **R5**.','- Clean rounds: **none**.')
    s=s.replace('- **Clean rounds:** R5.','- **Clean rounds:** none.')
    s=s.replace('while R5 was clean','with no clean round').replace('R5 was clean','no round was clean')
    s=s.replace('R5 was clean.','No round was clean.')
    p.write_text(s,encoding='utf-8')

# Make the current status prose unambiguous after the final round.
p=Path('STATUS.md'); s=p.read_text(encoding='utf-8')
s=s.replace('The current 4–5 September', 'The current 5–6 September')
s=s.replace('Defect-bearing rounds:** R1, R2, R3, R4, R5, R6, R7, R8, R9, R10.\n- **Clean rounds:** none.', 'Defect-bearing rounds:** R1, R2, R3, R4, R5, R6, R7, R8, R9, R10.\n- **Clean rounds:** none.')
p.write_text(s,encoding='utf-8')

# Existing release-truth contracts must follow current semantic truth rather than yesterday's branch/count.
for rel in [
    'sabri-network/tests/fifth-fresh-release-truth-contracts.php',
    'sabri-network/tests/forty-round-review-4-release-truth-contracts.php',
    'sabri-network/tests/seventh-fresh-ten-round-contracts.php',
]:
    p=Path(rel); s=p.read_text(encoding='utf-8')
    s=s.replace(old,current).replace(old_rounds,all_rounds).replace(old_rounds_and,all_rounds_and)
    s=s.replace('current 54-suite/10-JS','current 57-suite/10-JS')
    s=s.replace('current 54-suite gate','current 57-suite gate')
    s=s.replace("str_contains($text,'54')&&str_contains($text,'10')","str_contains($text,'57')&&str_contains($text,'10')")
    s=s.replace("str_contains($status,'**54**')&&str_contains($complete,'review suites')&&str_contains($complete,'**54**')","str_contains($status,'**57**')&&str_contains($complete,'review suites')&&str_contains($complete,'**57**')")
    s=s.replace("'54 PHP review suites'","'57 PHP review suites'")
    s=s.replace('54 PHP review suites','57 PHP review suites').replace('54-suite','57-suite')
    p.write_text(s,encoding='utf-8')

# Dedicated Round-10 permanent release truth regression.
r10=Path('sabri-network/tests/another-fresh-r10-release-truth-contracts.php')
r10.write_text(r'''<?php
/** Round 10 permanent regression: exact current-cycle release truth. */
declare(strict_types=1);
$root=dirname(__DIR__);$repo=dirname($root);$fail=[];$checks=0;
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$readRepo=static fn(string $p):string=>(string)file_get_contents($repo.'/'.$p);
$check=static function(bool $ok,string $m)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$m;};
$q=$read('tools/quality-check.sh');$qa=$read('QA-INVENTORY.txt');
$docs=['README'=>$readRepo('README.md'),'STATUS'=>$readRepo('STATUS.md'),'CODING'=>$readRepo('CODING-COMPLETENESS.md'),'plugin readme'=>$read('readme.txt'),'CHANGELOG'=>$read('CHANGELOG.md'),'boundary'=>$read('CURRENT-CANDIDATE-BOUNDARY.txt')];
$branch='review/file17-another-10-round-2026-09-05';$rounds='R1, R2, R3, R4, R5, R6, R7, R8, R9, R10';
foreach($docs as $name=>$text){
  $check(str_contains($text,$branch),"R10: $name identifies current review branch");
  $check(str_contains($text,$rounds),"R10: $name records all ten defect-bearing rounds");
  $check(str_contains($text,'57')&&str_contains($text,'10'),"R10: $name publishes 57-suite/10-JS current inventory");
  $check(!str_contains($text,'Clean rounds: **R5**')&&!str_contains($text,'R5 was clean'),"R10: $name does not publish the prior cycle R5-clean outcome as current truth");
}
$check(str_contains($qa,'57')&&str_contains($qa,'10'),'R10: QA inventory matches executable 57-suite/10-JS gate');
$check(str_contains($q,'another-fresh-r10-release-truth-contracts.php'),'R10: full quality gate invokes this permanent regression');
$check(str_contains($readRepo('MANIFEST.md'),'exact staged release tree'),'R10: manifest truth remains executable/exact-commit based');
if($fail){fwrite(STDERR,"Another fresh R10 release-truth failures (".count($fail)."/$checks):\n - ".implode("\n - ",$fail)."\n");exit(1);}echo "Another fresh R10 release-truth contracts: PASS ($checks checks)\n";
''',encoding='utf-8')

# Explicitly register the new permanent suite. Existing R8/R9 entries are retained.
p=Path('sabri-network/tools/quality-check.sh'); s=p.read_text(encoding='utf-8')
needle=' another-fresh-r6-spaces-contracts.php another-fresh-r7-relationship-contracts.php another-fresh-r8-messaging-privacy-search-contracts.php another-fresh-r9-operational-truth-contracts.php\n'
repl=' another-fresh-r6-spaces-contracts.php another-fresh-r7-relationship-contracts.php another-fresh-r8-messaging-privacy-search-contracts.php another-fresh-r9-operational-truth-contracts.php another-fresh-r10-release-truth-contracts.php\n'
if 'another-fresh-r10-release-truth-contracts.php' not in s:
    if needle not in s: raise SystemExit('R10 quality inventory insertion point missing')
    s=s.replace(needle,repl,1)
p.write_text(s,encoding='utf-8')
