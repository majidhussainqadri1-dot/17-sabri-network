from pathlib import Path

root = Path('sabri-network')
p = root / 'includes/class-sn-cf01-clinical-context.php'
t = p.read_text(encoding='utf-8')

# R31 Defect Ledger (frozen before correction):
# D1 membership/conversation/reference reads could collapse DB uncertainty into not-found.
# D2 participant count/class and direct-conversation checks could emit false clinical-context assertions on DB uncertainty.
# D3 idempotency source lookup could continue after a failed read.
# D4 destination/revocation reads and revocation CAS could misclassify DB failure as absence/conflict.
# D5 privacy export/erasure could claim empty/done or fatally count a failed read.
# D6 cleanup DB failure had no observable failure signal.

old = """        if ($conversation_id <= 0 || $actor_id <= 0 || !SN_DB::is_member($conversation_id, $actor_id)) {
            return self::not_found();
        }
        $conversation = self::conversation($conversation_id);
        if (!$conversation || (string) $conversation->status !== 'active' || self::direct_conversation_blocked($conversation, $actor_id)) {
            return self::not_found();
        }"""
new = """        if ($conversation_id <= 0 || $actor_id <= 0) {
            return self::not_found();
        }
        $is_member = SN_DB::is_member($conversation_id, $actor_id);
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$is_member) {
            return self::not_found();
        }
        $conversation = self::conversation($conversation_id);
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$conversation || (string) $conversation->status !== 'active') {
            return self::not_found();
        }
        $blocked = self::direct_conversation_blocked($conversation, $actor_id);
        if (is_wp_error($blocked)) {
            return $blocked;
        }
        if ($blocked) {
            return self::not_found();
        }"""
assert old in t, 'issue membership/conversation anchor missing'
t = t.replace(old, new, 1)

old = """            $existing = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE issued_by=%d AND idempotency_key=%s FOR UPDATE',
                $actor_id,
                $idempotency_hash
            ));
            if ($existing) {"""
new = """            $existing = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE issued_by=%d AND idempotency_key=%s FOR UPDATE',
                $actor_id,
                $idempotency_hash
            ));
            if ($wpdb->last_error !== '') {
                throw new RuntimeException('reference_lookup_failed');
            }
            if ($existing) {"""
assert old in t, 'idempotency lookup anchor missing'
t = t.replace(old, new, 1)

old = """            $event = SN_Outbox::enqueue(
                'conversation.clinical_context_reference_issued',"""
new = """            $participant_count = self::participant_count($conversation_id);
            if (is_wp_error($participant_count)) {
                throw new RuntimeException('participant_count_failed');
            }
            $event = SN_Outbox::enqueue(
                'conversation.clinical_context_reference_issued',"""
assert old in t, 'issue participant-count insertion anchor missing'
t = t.replace(old, new, 1)
t = t.replace("'conversation_state_hash' => self::conversation_state_hash($conversation, self::participant_count($conversation_id)),", "'conversation_state_hash' => self::conversation_state_hash($conversation, $participant_count),", 1)

old = """        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference_uuid=%s', $reference_uuid));
        if (!$row || (string) $row->status !== 'active' || self::timestamp((string) $row->expires_at) <= time()) {
            return self::not_found();
        }
        $conversation_id = (int) $row->conversation_id;
        if (!SN_DB::is_member($conversation_id, $actor_id)) {
            return self::not_found();
        }
        $conversation = self::conversation($conversation_id);
        if (!$conversation || (string) $conversation->status !== 'active' || self::direct_conversation_blocked($conversation, $actor_id)) {
            return self::not_found();
        }"""
new = """        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference_uuid=%s', $reference_uuid));
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$row || (string) $row->status !== 'active' || self::timestamp((string) $row->expires_at) <= time()) {
            return self::not_found();
        }
        $conversation_id = (int) $row->conversation_id;
        $is_member = SN_DB::is_member($conversation_id, $actor_id);
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$is_member) {
            return self::not_found();
        }
        $conversation = self::conversation($conversation_id);
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$conversation || (string) $conversation->status !== 'active') {
            return self::not_found();
        }
        $blocked = self::direct_conversation_blocked($conversation, $actor_id);
        if (is_wp_error($blocked)) {
            return $blocked;
        }
        if ($blocked) {
            return self::not_found();
        }"""
assert old in t, 'assertion source/membership anchor missing'
t = t.replace(old, new, 1)

old = """        $participants = self::participant_count($conversation_id);
        $now = time();"""
new = """        $participants = self::participant_count($conversation_id);
        if (is_wp_error($participants)) {
            return $participants;
        }
        $participant_class = self::participant_class($conversation_id, $actor_id);
        if (is_wp_error($participant_class)) {
            return $participant_class;
        }
        $now = time();"""
assert old in t, 'assertion participant truth anchor missing'
t = t.replace(old, new, 1)
t = t.replace("'actor_participant_class' => self::participant_class($conversation_id, $actor_id),", "'actor_participant_class' => $participant_class,", 1)

old = """        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT conversation_id FROM ' . self::table() . ' WHERE reference_uuid=%s AND status=%s',
            strtolower($reference_uuid),
            'active'
        ));
        if (!$row || !SN_DB::is_member((int) $row->conversation_id, $actor_id)) {
            return self::not_found();
        }"""
new = """        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT conversation_id FROM ' . self::table() . ' WHERE reference_uuid=%s AND status=%s',
            strtolower($reference_uuid),
            'active'
        ));
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$row) {
            return self::not_found();
        }
        $is_member = SN_DB::is_member((int) $row->conversation_id, $actor_id);
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$is_member) {
            return self::not_found();
        }"""
assert old in t, 'destination read anchor missing'
t = t.replace(old, new, 1)

old = """        $row = self::valid_uuid($reference_uuid)
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference_uuid=%s', $reference_uuid))
            : null;
        if (!$row || $actor_id <= 0) {"""
new = """        $row = self::valid_uuid($reference_uuid)
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE reference_uuid=%s', $reference_uuid))
            : null;
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        if (!$row || $actor_id <= 0) {"""
assert old in t, 'revoke read anchor missing'
t = t.replace(old, new, 1)
old = """        if ($changed !== 1) {
            return self::error('sn_cf01_reference_revoke_conflict', 'The reference changed before it could be revoked.', 409);
        }"""
new = """        if ($changed !== 1) {
            if ($changed === false || $wpdb->last_error !== '') {
                return self::storage_unavailable();
            }
            return self::error('sn_cf01_reference_revoke_conflict', 'The reference changed before it could be revoked.', 409);
        }"""
assert old in t, 'revoke CAS anchor missing'
t = t.replace(old, new, 1)

old = """        $wpdb->query($wpdb->prepare(
            \"UPDATE \" . self::table() . \" SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at<=%s LIMIT 500\",
            $now,
            $now
        ));"""
new = """        $changed = $wpdb->query($wpdb->prepare(
            \"UPDATE \" . self::table() . \" SET status='expired',updated_at=%s,version=version+1 WHERE status='active' AND expires_at<=%s LIMIT 500\",
            $now,
            $now
        ));
        if ($changed === false) {
            do_action('sn_cf01_cleanup_failed', (string) $wpdb->last_error);
        }"""
assert old in t, 'cleanup anchor missing'
t = t.replace(old, new, 1)

old = """        $data = [];
        foreach (is_array($rows) ? $rows : [] as $row) {"""
new = """        if ($wpdb->last_error !== '') {
            return ['data' => [], 'done' => false];
        }
        $rows = is_array($rows) ? $rows : [];
        $data = [];
        foreach ($rows as $row) {"""
assert old in t, 'export rows anchor missing'
t = t.replace(old, new, 1)
# count($rows) is now safe because rows is normalized array.

old = """        return [
            'items_removed' => $changed > 0,
            'items_retained' => true,
            'messages' => [__('Opaque references were revoked; minimal audit evidence remains under File 17 retention rules.', 'sabri-network')],
            'done' => true,
        ];"""
new = """        if ($changed === false) {
            return [
                'items_removed' => false,
                'items_retained' => true,
                'messages' => [__('Communication-context erasure could not be verified; retry is required.', 'sabri-network')],
                'done' => false,
            ];
        }
        return [
            'items_removed' => $changed > 0,
            'items_retained' => true,
            'messages' => [__('Opaque references were revoked; minimal audit evidence remains under File 17 retention rules.', 'sabri-network')],
            'done' => true,
        ];"""
assert old in t, 'eraser completion anchor missing'
t = t.replace(old, new, 1)

old = """    private static function participant_count(int $conversation_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL',
            $conversation_id
        ));
    }

    private static function participant_class(int $conversation_id, int $actor_id): string {
        $role = sanitize_key(SN_DB::member_role($conversation_id, $actor_id));
        return in_array($role, ['owner', 'administrator', 'moderator', 'editor', 'member', 'observer'], true) ? $role : 'member';
    }

    private static function direct_conversation_blocked(object $conversation, int $actor_id): bool {
        global $wpdb;
        if ((string) $conversation->type !== 'direct') {
            return false;
        }
        $other = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY id ASC LIMIT 1',
            (int) $conversation->id,
            $actor_id
        ));
        return $other <= 0 || SN_DB::is_blocked($actor_id, $other);
    }"""
new = """    private static function participant_count(int $conversation_id): int|WP_Error {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND left_at IS NULL',
            $conversation_id
        ));
        if ($wpdb->last_error !== '' || $count === null) {
            return self::storage_unavailable();
        }
        return (int) $count;
    }

    private static function participant_class(int $conversation_id, int $actor_id): string|WP_Error {
        global $wpdb;
        $role = sanitize_key(SN_DB::member_role($conversation_id, $actor_id));
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        return in_array($role, ['owner', 'administrator', 'moderator', 'editor', 'member', 'observer'], true) ? $role : 'member';
    }

    private static function direct_conversation_blocked(object $conversation, int $actor_id): bool|WP_Error {
        global $wpdb;
        if ((string) $conversation->type !== 'direct') {
            return false;
        }
        $other_raw = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM ' . SN_DB::table('members') . ' WHERE conversation_id=%d AND user_id<>%d AND left_at IS NULL ORDER BY id ASC LIMIT 1',
            (int) $conversation->id,
            $actor_id
        ));
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        $other = (int) $other_raw;
        if ($other <= 0) {
            return true;
        }
        $blocked = SN_DB::is_blocked($actor_id, $other);
        if ($wpdb->last_error !== '') {
            return self::storage_unavailable();
        }
        return $blocked;
    }"""
assert old in t, 'helper truth anchor missing'
t = t.replace(old, new, 1)

old = """    private static function not_found(): WP_Error {
        return self::error('sn_cf01_reference_not_found', 'The communication-context reference is unavailable.', 404);
    }

    private static function error(string $code, string $message, int $status): WP_Error {"""
new = """    private static function not_found(): WP_Error {
        return self::error('sn_cf01_reference_not_found', 'The communication-context reference is unavailable.', 404);
    }

    private static function storage_unavailable(): WP_Error {
        return self::error('sn_cf01_storage_unavailable', 'Communication-context storage truth could not be verified safely.', 503);
    }

    private static function error(string $code, string $message, int $status): WP_Error {"""
assert old in t, 'storage helper anchor missing'
t = t.replace(old, new, 1)

p.write_text(t, encoding='utf-8')

q = root / 'tests/ninth-fresh/ninth-fresh-forty-round-contracts.php'
s = q.read_text(encoding='utf-8')
marker = '\nif ($fail) {\n'
assert marker in s and '// Round 31 —' not in s
block = r'''

// Round 31 — CF-01 clinical-context assertions and privacy workflows preserve database truth.
$cf01=$read('includes/class-sn-cf01-clinical-context.php');
$check(str_contains($cf01,'sn_cf01_storage_unavailable') && substr_count($cf01,'return self::storage_unavailable();')>=8, 'Round 31: CF-01 membership/conversation/reference DB uncertainty must fail closed as unavailable, not not-found/conflict.');
$check(str_contains($cf01,'private static function participant_count(int $conversation_id): int|WP_Error') && str_contains($cf01,'$count === null') && str_contains($cf01,'private static function participant_class(int $conversation_id, int $actor_id): string|WP_Error'), 'Round 31: CF-01 participant count/class must not fabricate clinical-context state under DB uncertainty.');
$check(str_contains($cf01,'private static function direct_conversation_blocked(object $conversation, int $actor_id): bool|WP_Error') && str_contains($cf01,'$other_raw') && substr_count($cf01,'is_wp_error($blocked)')>=2, 'Round 31: direct-conversation block checks must preserve DB uncertainty.');
$check(str_contains($cf01,"'done' => false") && str_contains($cf01,'Communication-context erasure could not be verified; retry is required.') && str_contains($cf01,"return ['data' => [], 'done' => false];"), 'Round 31: CF-01 privacy export/erasure must remain retryable when DB truth is unavailable.');
$check(str_contains($cf01,"do_action('sn_cf01_cleanup_failed'") && str_contains($cf01,"throw new RuntimeException('reference_lookup_failed')"), 'Round 31: CF-01 cleanup/idempotency read failures must be observable and fail closed.');
'''
q.write_text(s.replace(marker, block + marker, 1), encoding='utf-8')
