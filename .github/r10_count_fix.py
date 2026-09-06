from pathlib import Path
paths=[
'README.md','STATUS.md','CODING-COMPLETENESS.md',
'sabri-network/readme.txt','sabri-network/CHANGELOG.md','sabri-network/CURRENT-CANDIDATE-BOUNDARY.txt','sabri-network/QA-INVENTORY.txt',
'sabri-network/tests/fifth-fresh-release-truth-contracts.php','sabri-network/tests/forty-round-review-4-release-truth-contracts.php','sabri-network/tests/seventh-fresh-ten-round-contracts.php','sabri-network/tests/another-fresh-r10-release-truth-contracts.php','sabri-network/tests/four-plan-review-4-fresh-release-contracts.php'
]
for name in paths:
    p=Path(name); s=p.read_text(encoding='utf-8')
    s=s.replace('57 PHP review suites','59 PHP review suites').replace('57 PHP review suite','59 PHP review suite')
    s=s.replace('57-suite','59-suite').replace('57 suites','59 suites').replace('**57**','**59**')
    s=s.replace("str_contains($text,'57')&&str_contains($text,'10')","str_contains($text,'59')&&str_contains($text,'10')")
    s=s.replace("str_contains($status,'**57**')&&str_contains($complete,'review suites')&&str_contains($complete,'**57**')","str_contains($status,'**59**')&&str_contains($complete,'review suites')&&str_contains($complete,'**59**')")
    s=s.replace("str_contains($qa,'57')&&str_contains($qa,'10')","str_contains($qa,'59')&&str_contains($qa,'10')")
    s=s.replace("str_contains($status,'review suites')&&str_contains($status,'**57**')","str_contains($status,'review suites')&&str_contains($status,'**59**')")
    s=s.replace('current explicit 57-suite gate','current explicit 59-suite gate')
    s=s.replace('57-suite/10-JS','59-suite/10-JS')
    p.write_text(s,encoding='utf-8')
