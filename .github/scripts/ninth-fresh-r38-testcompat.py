from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
p = ROOT / 'sabri-network/includes/class-sn-outbox.php'
text = p.read_text()
old = "if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('incoming_event_transaction_failed', 'The incoming event transaction could not be started.');"
new = "if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('incoming_event_storage_unavailable', 'Incoming event storage truth could not be verified.', ['status'=>503]);"
if old not in text:
    raise SystemExit('R38 incoming transaction compatibility target missing')
p.write_text(text.replace(old, new, 1))
