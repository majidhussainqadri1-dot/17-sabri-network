from pathlib import Path
p=Path('sabri-network/includes/class-sn-cf01-clinical-context.php')
t=p.read_text(encoding='utf-8')
t=t.replace("$wpdb->last_error !== ''", "($wpdb->last_error ?? '') !== ''")
t=t.replace("(string) $wpdb->last_error", "(string) ($wpdb->last_error ?? '')")
p.write_text(t,encoding='utf-8')
