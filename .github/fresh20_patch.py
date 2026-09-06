from pathlib import Path

# R05-D01: refresh File-00 identity/contact truth after recipient pair locks and before any conversation reservation side effect.
p=Path('sabri-network/includes/class-sn-smail-runtime-hardening.php')
s=p.read_text(encoding='utf-8')
old="""            foreach($recipients as $recipient){$allowed=SN_Policy::can_contact($sender,$recipient,count($recipients)===1?'message':'group');if(is_wp_error($allowed))return $allowed;}
            $conversation=SN_Central_Plan_Hardening::resolve_smail_conversation($sender,$recipients,$subject,$client_key);if(is_wp_error($conversation))return $conversation;$conversation=(int)$conversation;if($conversation<=0)return new WP_Error('smail_conversation_failed','The Smail conversation could not be resolved.',['status'=>500]);
"""
new="""            SN_Membership_Assertions::clear_cache($sender);
            $fresh_access=SN_Policy::access();if(is_wp_error($fresh_access))return $fresh_access;
            foreach($recipients as $recipient){SN_Membership_Assertions::clear_cache($recipient);$allowed=SN_Policy::can_contact($sender,$recipient,count($recipients)===1?'message':'group');if(is_wp_error($allowed))return $allowed;}
            $conversation=SN_Central_Plan_Hardening::resolve_smail_conversation($sender,$recipients,$subject,$client_key);if(is_wp_error($conversation))return $conversation;$conversation=(int)$conversation;if($conversation<=0)return new WP_Error('smail_conversation_failed','The Smail conversation could not be resolved.',['status'=>500]);
"""
if old not in s: raise SystemExit('R05 Smail pre-reservation target missing')
s=s.replace(old,new,1)
p.write_text(s,encoding='utf-8')

# R05-D02: group reservation must prove transaction start.
p=Path('sabri-network/includes/class-sn-central-plan-hardening.php')
s=p.read_text(encoding='utf-8')
start=s.index('public static function resolve_smail_conversation')
old="$wpdb->query('START TRANSACTION');\n        try {"
pos=s.find(old,start)
if pos<0: raise SystemExit('R05 Smail group transaction target missing')
new="""if ($wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('smail_conversation_failed', 'The Smail group reservation transaction could not start.', ['status' => 500]);
        }
        try {"""
s=s[:pos]+s[pos:].replace(old,new,1)
p.write_text(s,encoding='utf-8')

# Permanent regression.
p=Path('sabri-network/tests/seventh-fresh-ten-round-contracts.php')
s=p.read_text(encoding='utf-8')
anchor="$smailRuntime=$read('includes/class-sn-smail-runtime-hardening.php');\n"
if anchor not in s: raise SystemExit('R05 regression variable anchor missing')
marker='// Fresh20 R05 Smail reservation regressions.'
if marker not in s:
    insert="""
// Fresh20 R05 Smail reservation regressions.
$smailSendPos=strpos($smailRuntime,'public static function send');
$smailResolvePos=strpos($smailRuntime,'SN_Central_Plan_Hardening::resolve_smail_conversation',$smailSendPos);
$smailFreshPos=strpos($smailRuntime,'SN_Membership_Assertions::clear_cache($sender);',$smailSendPos);
$check($smailFreshPos!==false&&$smailResolvePos!==false&&$smailFreshPos<$smailResolvePos,'Fresh20 R05: Smail must refresh File-00 actor truth under recipient locks before conversation reservation.');
$check(str_contains($smailRuntime,'SN_Membership_Assertions::clear_cache($recipient);')&&str_contains($smailRuntime,'$fresh_access=SN_Policy::access();'),'Fresh20 R05: Smail must refresh recipient assertions and canonical access before positive reservation.');
$centralSmailPos=strpos($centralPlan,'public static function resolve_smail_conversation');
$centralSmail=substr($centralPlan,$centralSmailPos,7000);
$check(str_contains($centralSmail,"START TRANSACTION') === false")&&str_contains($centralSmail,'reservation transaction could not start'),'Fresh20 R05: Smail group reservation must prove transaction start before inserts.');
"""
    tail=s.rfind('if($fail){')
    if tail<0: raise SystemExit('R05 regression tail missing')
    s=s[:tail]+insert+s[tail:]
p.write_text(s,encoding='utf-8')
