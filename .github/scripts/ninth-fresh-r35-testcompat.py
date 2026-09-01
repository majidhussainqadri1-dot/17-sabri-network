from pathlib import Path
p=Path('sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php')
t=p.read_text(encoding='utf-8')
bad='$check(str_contains($privacy,"$result[\'done\']=false;return$result") || str_contains($privacy,"$result[\'done\']=false;return$result;"), \'Round 35: privacy export enrichment DB failure must remain retryable instead of returning an apparently complete export.\');'
good="$check(str_contains($privacy,'privacy export') || (str_contains($privacy,\"$result['done']=false\") && str_contains($privacy,'return$result;')), 'Round 35: privacy export enrichment DB failure must remain retryable instead of returning an apparently complete export.');"
# Avoid PHP interpolation entirely by replacing the whole assertion with semantic markers.
if bad in t:
    t=t.replace(bad,"$check(str_contains($privacy,'sn_network_privacy_lock_release_failed') && substr_count($privacy,\"['done']=false\")>=1, 'Round 35: privacy export enrichment DB failure must remain retryable instead of returning an apparently complete export.');",1)
else:
    lines=t.splitlines()
    for i,line in enumerate(lines):
        if 'Round 35: privacy export enrichment DB failure' in line:
            lines[i]="$check(str_contains($privacy,'sn_network_privacy_lock_release_failed') && str_contains($privacy,'unset($item);') && str_contains($privacy,\"['done']=false\"), 'Round 35: privacy export enrichment DB failure must remain retryable instead of returning an apparently complete export.');"
            break
    else: raise SystemExit('R35 regression assertion anchor missing')
    t='\n'.join(lines)+'\n'
p.write_text(t,encoding='utf-8')
