from pathlib import Path
root=Path('sabri-network'); p=root/'includes/class-sn-realtime-runtime-hardening.php'; t=p.read_text(encoding='utf-8')
# R34 frozen Defect Ledger:
# D1 GET_LOCK NULL/DB failure was cast to 0 and misreported as ordinary 409 contention.
# D2 RELEASE_LOCK DB failure was silent, leaving lease-release failure unobservable.
old="""            foreach ($locks as $lock) {
                $ok=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
                if($ok!==1)return new WP_Error('sn_realtime_busy','The realtime state is changing. Retry the request.',['status'=>409]);
                $held[]=$lock;
            }
            return $callback();
        } finally {
            foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }"""
new="""            foreach ($locks as $lock) {
                $raw=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,self::LOCK_TIMEOUT));
                if(($wpdb->last_error ?? '')!==''||$raw===null)return new WP_Error('sn_realtime_lock_unavailable','Realtime concurrency control could not be verified safely.',['status'=>503]);
                $ok=(int)$raw;
                if($ok!==1)return new WP_Error('sn_realtime_busy','The realtime state is changing. Retry the request.',['status'=>409]);
                $held[]=$lock;
            }
            return $callback();
        } finally {
            foreach(array_reverse($held) as $lock){$released=$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));if(($wpdb->last_error ?? '')!==''||$released===null)do_action('sn_realtime_lock_release_failed',$lock,(string)($wpdb->last_error ?? ''));}
        }"""
assert old in t, 'R34 lock anchor missing'; t=t.replace(old,new,1); p.write_text(t,encoding='utf-8')
q=root/'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'; s=q.read_text(encoding='utf-8'); marker='\nif ($fail) {\n'; assert marker in s and '// Round 34 —' not in s
block=r'''

// Round 34 — realtime concurrency locks distinguish infrastructure uncertainty from contention.
$rt=$read('includes/class-sn-realtime-runtime-hardening.php');
$check(str_contains($rt,'sn_realtime_lock_unavailable') && str_contains($rt,'$raw===null') && str_contains($rt,"($wpdb->last_error ?? '')!==''"), 'Round 34: realtime GET_LOCK DB uncertainty must fail closed as 503, not ordinary 409 contention.');
$check(str_contains($rt,'sn_realtime_lock_release_failed') && str_contains($rt,'RELEASE_LOCK(%s)'), 'Round 34: realtime lock-release failure must be observable.');
'''
q.write_text(s.replace(marker,block+marker,1),encoding='utf-8')
