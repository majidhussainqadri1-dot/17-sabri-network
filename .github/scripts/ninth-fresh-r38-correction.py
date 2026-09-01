from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def replace_method(path: str, signature: str, replacement: str) -> None:
    p = ROOT / path
    text = p.read_text()
    start = text.find(signature)
    if start < 0:
        raise SystemExit(f'method signature missing: {path}: {signature}')
    brace = text.find('{', start)
    if brace < 0:
        raise SystemExit(f'method opening brace missing: {path}: {signature}')
    depth = 0
    end = None
    for i in range(brace, len(text)):
        ch = text[i]
        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                end = i + 1
                break
    if end is None:
        raise SystemExit(f'method closing brace missing: {path}: {signature}')
    p.write_text(text[:start] + replacement + text[end:])


def replace_once(path: str, old: str, new: str) -> None:
    p = ROOT / path
    text = p.read_text()
    if old not in text:
        raise SystemExit(f'replacement target missing: {path}: {old[:160]!r}')
    p.write_text(text.replace(old, new, 1))

# R38-A — queue enumeration must distinguish DB failure from an empty outbox.
replace_method(
    'sabri-network/includes/class-sn-outbox.php',
    '    public static function dispatch_batch(): void {',
    '''    public static function dispatch_batch(): void {
        global $wpdb;
        $now = current_time('mysql', true);
        $stale = gmdate('Y-m-d H:i:s', time() - self::LOCK_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM ".self::outbox_table()." WHERE ((status IN ('pending','retry') AND available_at<=%s) OR (status='processing' AND locked_at<%s)) ORDER BY id ASC LIMIT %d",
            $now,
            $stale,
            self::BATCH_SIZE
        ));
        if (($wpdb->last_error ?? '') !== '' || !is_array($ids)) {
            SN_DB::audit('event_dispatch_queue_read_failed', 'event', 0, 'failure', ['reason'=>(string)$wpdb->last_error], 0);
            return;
        }
        foreach (array_map('absint', $ids) as $id) self::dispatch_one($id);
    }'''
)

# R38-B — establish the incoming-event claim and payload truth under one row lock.
replace_method(
    'sabri-network/includes/class-sn-outbox.php',
    '    public static function consume_incoming(string $producer, string $event_uuid, array $payload, callable $handler): bool|WP_Error {',
    '''    public static function consume_incoming(string $producer, string $event_uuid, array $payload, callable $handler): bool|WP_Error {
        global $wpdb;
        $producer = sanitize_key($producer);
        if ($producer === '' || !wp_is_uuid($event_uuid, 4)) return new WP_Error('invalid_incoming_event', 'The incoming event identity is invalid.');
        $clean = self::sanitize_payload($payload);
        $json = (string) wp_json_encode($clean);
        if ($json === '' || strlen($json) > self::MAX_PAYLOAD_BYTES) return new WP_Error('incoming_payload_invalid', 'The incoming event metadata is invalid.');
        $hash = hash('sha256', $json);
        $table = self::inbox_table();
        $now = current_time('mysql', true);

        if ($wpdb->query('START TRANSACTION') === false) return new WP_Error('incoming_event_transaction_failed', 'The incoming event transaction could not be started.');
        try {
            // The unique producer/event identity is materialized before SELECT ... FOR UPDATE.
            // Concurrent consumers therefore serialize on the same canonical inbox row.
            $claim = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (producer,event_uuid,payload_hash,status,attempts,last_error,created_at,updated_at) VALUES (%s,%s,%s,'processing',0,'',%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                $producer,
                $event_uuid,
                $hash,
                $now,
                $now
            ));
            if ($claim === false || ($wpdb->last_error ?? '') !== '') throw new RuntimeException('incoming_event_claim_failed');

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE producer=%s AND event_uuid=%s FOR UPDATE",
                $producer,
                $event_uuid
            ));
            if (($wpdb->last_error ?? '') !== '' || !$row) throw new RuntimeException('incoming_event_claim_read_failed');
            if (!hash_equals((string)$row->payload_hash, $hash)) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('incoming_event_conflict', 'The incoming event identity conflicts with prior metadata.');
            }
            if ((string)$row->status === 'processed') {
                $wpdb->query('ROLLBACK');
                return true;
            }

            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='processing',attempts=attempts+1,last_error='',updated_at=%s WHERE id=%d AND status<>'processed'",
                $now,
                (int)$row->id
            ));
            if ($claimed !== 1 || ($wpdb->last_error ?? '') !== '') throw new RuntimeException('incoming_event_claim_failed');

            $result = $handler($clean);
            if (is_wp_error($result) || $result === false) throw new RuntimeException(is_wp_error($result) ? $result->get_error_code() : 'incoming_event_handler_failed');

            $done = current_time('mysql', true);
            $completed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='processed',last_error='',processed_at=%s,updated_at=%s WHERE id=%d AND status='processing'",
                $done,
                $done,
                (int)$row->id
            ));
            if ($completed !== 1 || ($wpdb->last_error ?? '') !== '') throw new RuntimeException('incoming_event_completion_failed');
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('incoming_event_commit_failed');
            return true;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            $failed = current_time('mysql', true);
            $error = mb_substr(sanitize_text_field($e->getMessage()), 0, 500);
            $recorded = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (producer,event_uuid,payload_hash,status,attempts,last_error,created_at,updated_at) VALUES (%s,%s,%s,'failed',1,%s,%s,%s) ON DUPLICATE KEY UPDATE status=IF(status='processed','processed','failed'),attempts=IF(status='processed',attempts,attempts+1),last_error=IF(status='processed',last_error,VALUES(last_error)),updated_at=IF(status='processed',updated_at,VALUES(updated_at))",
                $producer,
                $event_uuid,
                $hash,
                $error,
                $failed,
                $failed
            ));
            if ($recorded === false || ($wpdb->last_error ?? '') !== '') {
                SN_DB::audit('incoming_event_failure_record_failed', 'event', 0, 'failure', [
                    'producer'=>$producer,
                    'event_uuid_hash'=>hash('sha256', $event_uuid),
                    'reason'=>$error,
                    'db_error'=>(string)$wpdb->last_error,
                ], 0);
            }
            return new WP_Error('incoming_event_failed', 'The incoming event could not be consumed transactionally.');
        }
    }'''
)

# R38-C — File 17 -> File 19 notification metadata uses the durable outbox, never a best-effort synchronous handoff.
replace_once(
    'sabri-network/includes/class-sn-central-plan-hardening.php',
    "        add_filter('sn_network_notification_handled', [self::class, 'route_notification_to_file19'], PHP_INT_MAX, 2);",
    "        add_filter('sn_network_notification_handled', [self::class, 'route_notification_to_file19'], PHP_INT_MAX, 2);\n        add_filter('sn_network_outbox_delivery_result', [self::class, 'deliver_notification_outbox'], PHP_INT_MAX, 2);"
)

replace_method(
    'sabri-network/includes/class-sn-central-plan-hardening.php',
    '    public static function route_notification_to_file19(bool $handled, array $event): bool {',
    '''    public static function route_notification_to_file19(bool $handled, array $event): bool {
        $requested = [
            'producer' => 'file-17',
            'recipient_id' => absint($event['user_id'] ?? 0),
            'type' => sanitize_key((string) ($event['type'] ?? '')),
            'title' => sanitize_text_field((string) ($event['title'] ?? '')),
            'entity_type' => sanitize_key((string) ($event['entity_type'] ?? '')),
            'entity_id' => absint($event['entity_id'] ?? 0),
            'created_at' => sanitize_text_field((string) ($event['created_at'] ?? current_time('mysql', true))),
        ];
        $requested['idempotency_key'] = 'file17-notification:' . hash('sha256', implode('|', [
            (string)$requested['recipient_id'],
            (string)$requested['type'],
            (string)$requested['entity_type'],
            (string)$requested['entity_id'],
            (string)$requested['created_at'],
        ]));

        $queued = class_exists('SN_Outbox')
            ? SN_Outbox::enqueue(
                'notification.requested',
                'notification',
                (int)$requested['entity_id'],
                $requested,
                (string)$requested['idempotency_key']
            )
            : new WP_Error('notification_outbox_unavailable', 'The durable File 17 notification outbox is unavailable.');

        if (is_wp_error($queued)) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_outbox_enqueue_failed', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'],
                'recipient_id'=>$requested['recipient_id'],
                'reason'=>$queued->get_error_code(),
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
                'prior_handler_claimed'=>$handled,
            ], 0);
            // The deprecated File-17 notification centre remains prohibited even when
            // durability storage itself is unavailable; the failure is explicit/auditable.
            return true;
        }

        do_action('sn_network_event_queued', (int)$queued, 'notification.requested');
        if (class_exists('SN_DB')) SN_DB::audit('notification_outbox_queued', $requested['entity_type'], $requested['entity_id'], 'success', [
            'notification_type'=>$requested['type'],
            'recipient_id'=>$requested['recipient_id'],
            'event_id'=>(int)$queued,
            'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            'prior_handler_claimed'=>$handled,
        ], 0);
        return true;
    }'''
)

# Insert the durable File-19 outbox consumer immediately after the routing method.
central = ROOT / 'sabri-network/includes/class-sn-central-plan-hardening.php'
text = central.read_text()
anchor = "\n\n    /** File 17 no longer exposes a second notification center. */"
if anchor not in text:
    raise SystemExit('central notification insertion anchor missing')
consumer = r'''

    /** Deliver a durable File-17 notification fact to canonical owner File 19. */
    public static function deliver_notification_outbox($ack, array $event) {
        if ((string)($event['type'] ?? '') !== 'notification.requested') return $ack;
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $requested = [
            'producer' => 'file-17',
            'recipient_id' => absint($payload['recipient_id'] ?? 0),
            'type' => sanitize_key((string)($payload['type'] ?? '')),
            'title' => sanitize_text_field((string)($payload['title'] ?? '')),
            'entity_type' => sanitize_key((string)($payload['entity_type'] ?? '')),
            'entity_id' => absint($payload['entity_id'] ?? 0),
            'created_at' => sanitize_text_field((string)($payload['created_at'] ?? '')),
            'idempotency_key' => sanitize_text_field((string)($payload['idempotency_key'] ?? '')),
        ];
        if ($requested['recipient_id'] <= 0 || $requested['type'] === '' || $requested['idempotency_key'] === '') {
            return new WP_Error('notification_payload_invalid', 'The durable File 19 notification payload is invalid.');
        }

        $ready = class_exists('SN_Seventh_Fresh_R13_Hardening')
            ? SN_Seventh_Fresh_R13_Hardening::file19_ready()
            : (has_action('sn_network_notification_requested') !== false && apply_filters('sn_network_file19_notification_adapter_ready', false) === true);
        if (!$ready) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_file19_unavailable', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'],
                'recipient_id'=>$requested['recipient_id'],
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
            return new WP_Error('notification_file19_unavailable', 'The canonical File 19 notification adapter is not ready.');
        }

        try {
            do_action('sn_network_notification_requested', $requested);
            $delivery = apply_filters('sn_network_notification_delivery_result', null, $requested);
            if (is_wp_error($delivery) || $delivery !== true) {
                $reason = is_wp_error($delivery) ? $delivery->get_error_code() : 'missing_explicit_ack';
                if (class_exists('SN_DB')) SN_DB::audit('notification_file19_handoff_unacknowledged', $requested['entity_type'], $requested['entity_id'], 'failure', [
                    'notification_type'=>$requested['type'],
                    'recipient_id'=>$requested['recipient_id'],
                    'reason'=>$reason,
                    'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
                ], 0);
                return new WP_Error('notification_file19_handoff_unacknowledged', 'File 19 did not explicitly acknowledge the notification handoff.');
            }
            if (class_exists('SN_DB')) SN_DB::audit('notification_deferred_to_file19', $requested['entity_type'], $requested['entity_id'], 'success', [
                'notification_type'=>$requested['type'],
                'recipient_id'=>$requested['recipient_id'],
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
            return true;
        } catch (Throwable $error) {
            if (class_exists('SN_DB')) SN_DB::audit('notification_file19_handoff_failed', $requested['entity_type'], $requested['entity_id'], 'failure', [
                'notification_type'=>$requested['type'],
                'recipient_id'=>$requested['recipient_id'],
                'reason'=>$error->getMessage(),
                'idempotency_key_hash'=>hash('sha256', (string)$requested['idempotency_key']),
            ], 0);
            return new WP_Error('notification_file19_handoff_failed', 'The File 19 notification handoff failed and remains retryable.');
        }
    }
'''
if 'public static function deliver_notification_outbox' not in text:
    central.write_text(text.replace(anchor, consumer + anchor, 1))

# R38 permanent regression contracts.
test = ROOT / 'sabri-network/tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
text = test.read_text()
marker = '\nif ($fail) {'
if marker not in text:
    raise SystemExit('ninth-fresh test insertion marker missing')
block = r'''

// Round 38 — outbox queue truth, inbox concurrency and File-19 notification delivery are durable/retryable.
$outbox=$read('includes/class-sn-outbox.php');$central=$read('includes/class-sn-central-plan-hardening.php');
$dispatchPos=strpos($outbox,'public static function dispatch_batch');$dispatchEnd=$dispatchPos===false?false:strpos($outbox,'public static function dispatch_one',$dispatchPos);$dispatchSeg=$dispatchPos===false?'':substr($outbox,$dispatchPos,($dispatchEnd===false?strlen($outbox):$dispatchEnd)-$dispatchPos);
$check(str_contains($dispatchSeg,'$wpdb->last_error') && str_contains($dispatchSeg,'event_dispatch_queue_read_failed'), 'Round 38: an outbox queue-read DB failure must never become an ordinary empty queue.');
$incomingPos=strpos($outbox,'public static function consume_incoming');$incomingEnd=$incomingPos===false?false:strpos($outbox,'public static function admin_events',$incomingPos);$incomingSeg=$incomingPos===false?'':substr($outbox,$incomingPos,($incomingEnd===false?strlen($outbox):$incomingEnd)-$incomingPos);
$check(str_contains($incomingSeg,'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)') && str_contains($incomingSeg,'FOR UPDATE') && strpos($incomingSeg,'FOR UPDATE') < strpos($incomingSeg,'$handler($clean)'), 'Round 38: concurrent incoming-event consumers must serialize on the canonical inbox row before handler execution.');
$check(str_contains($central,"add_filter('sn_network_outbox_delivery_result', [self::class, 'deliver_notification_outbox'], PHP_INT_MAX, 2)") && str_contains($central,"SN_Outbox::enqueue(") && str_contains($central,"'notification.requested'"), 'Round 38: File-19 notification facts must enter the durable File-17 outbox instead of relying on best-effort synchronous handoff.');
$check(str_contains($central,'notification_outbox_enqueue_failed') && str_contains($central,'notification_outbox_queued') && str_contains($central,'notification_file19_handoff_unacknowledged'), 'Round 38: notification durability and explicit File-19 acknowledgement failures must remain observable.');
$check(str_contains($central,"return new WP_Error('notification_file19_unavailable'") && str_contains($central,"return new WP_Error('notification_file19_handoff_unacknowledged'") && str_contains($central,'notification_deferred_to_file19'), 'Round 38: File-19 outage/unacknowledged delivery must remain retryable through the outbox and only explicit acknowledgement may succeed.');
'''
if 'Round 38 — outbox queue truth' not in text:
    test.write_text(text.replace(marker, block + marker, 1))
