<?php
/** Fifth fresh review: cross-file context privacy and release-boundary hardening. */
declare(strict_types=1);
defined('ABSPATH') || exit;

final class SN_Fifth_Fresh_Integration_Hardening {
    private const BATCH = 100;

    public static function register(): void {
        // Override the two cross-file reference erasers after their native callbacks,
        // while leaving the global File-17 legal-hold guard at priority 9999 last.
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'override_erasers'], 9600);
        // A same hostname on a different TCP port is a different web origin. Reject
        // provider projections that native host-only checks would otherwise accept.
        add_filter('sn_network_context_projection', [self::class, 'enforce_projection_origin_port'], PHP_INT_MAX, 5);
    }

    public static function override_erasers(array $erasers): array {
        if (isset($erasers['sabri-network-contexts'])) {
            $erasers['sabri-network-contexts']['callback'] = [self::class, 'erase_contexts'];
        }
        if (isset($erasers['sabri-network-cf01-references'])) {
            $erasers['sabri-network-cf01-references']['callback'] = [self::class, 'erase_cf01_references'];
        }
        return $erasers;
    }

    public static function enforce_projection_origin_port($projection, string $provider, string $object, int $conversation, int $actor) {
        if (!is_array($projection) || !isset($projection['url'])) return $projection;
        $parts = wp_parse_url((string) $projection['url']);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !is_array($home)) return null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $home_scheme = strtolower((string) ($home['scheme'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $home_port = isset($home['port']) ? (int) $home['port'] : ($home_scheme === 'https' ? 443 : 80);
        if ($scheme !== 'https'
            || $home_scheme !== 'https'
            || strcasecmp((string) ($parts['host'] ?? ''), (string) ($home['host'] ?? '')) !== 0
            || $port !== $home_port
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }
        return $projection;
    }

    /**
     * File 08/18/21 object truth remains with the provider. Erasure removes the
     * File-17 attachment attribution in bounded batches and never reports success
     * after a failed database write.
     */
    public static function erase_contexts(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $table = SN_DB::table('conversation_contexts');
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table WHERE attached_by=%d ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        )) ?: []);
        if (!$ids) return self::done();
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Conversation-context erasure could not start.');
        $removed = 0;
        try {
            foreach ($ids as $id) {
                $changed = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET attached_by=0,updated_at=%s,version=version+1 WHERE id=%d AND attached_by=%d",
                    current_time('mysql', true),
                    $id,
                    $uid
                ));
                if ($changed !== 1) throw new RuntimeException('context_attribution_erase_conflict');
                $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('context_attribution_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('context_privacy_erase_failed', 'user', $uid, 'failure', ['reason'=>$e->getMessage()], 0);
            return self::retry('Conversation-context erasure could not be committed.');
        }
        $more = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE attached_by=%d LIMIT 1", $uid));
        return ['items_removed'=>$removed>0,'items_retained'=>false,'messages'=>[],'done'=>!$more];
    }

    /**
     * CF-01 owns no clinical truth here. Issuer-owned opaque references are revoked
     * in bounded batches; minimal reference/audit evidence remains under File-17
     * retention and any active legal-hold policy.
     */
    public static function erase_cf01_references(string $email, int $page = 1): array {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) return self::done();
        $uid = (int)$user->ID;
        $table = SN_DB::table('cf01_context_refs');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,version FROM $table WHERE issued_by=%d AND status='active' ORDER BY id ASC LIMIT %d",
            $uid,
            self::BATCH
        ));
        if (!is_array($rows)) return self::retry('Clinical-context reference erasure could not be read safely.');
        if (!$rows) {
            return [
                'items_removed'=>false,
                'items_retained'=>true,
                'messages'=>['Opaque reference and audit evidence remains subject to File-17 retention and legal-hold rules.'],
                'done'=>true,
            ];
        }
        $now = current_time('mysql', true);
        if ($wpdb->query('START TRANSACTION') === false) return self::retry('Clinical-context reference erasure could not start.');
        $removed = 0;
        try {
            foreach ($rows as $row) {
                $changed = $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET status='revoked',revoked_at=COALESCE(revoked_at,%s),updated_at=%s,version=version+1 WHERE id=%d AND issued_by=%d AND status='active' AND version=%d",
                    $now,
                    $now,
                    (int)$row->id,
                    $uid,
                    (int)$row->version
                ));
                if ($changed !== 1) throw new RuntimeException('cf01_reference_erase_conflict');
                $removed++;
            }
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException('cf01_reference_erase_commit_failed');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            SN_DB::audit('cf01_reference_privacy_erase_failed', 'user', $uid, 'failure', ['reason'=>$e->getMessage()], 0);
            return self::retry('Clinical-context reference erasure could not be committed.');
        }
        $more = (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE issued_by=%d AND status='active' LIMIT 1", $uid));
        return [
            'items_removed'=>$removed>0,
            'items_retained'=>true,
            'messages'=>['Opaque reference and audit evidence remains subject to File-17 retention and legal-hold rules.'],
            'done'=>!$more,
        ];
    }

    private static function retry(string $message): array {
        return ['items_removed'=>false,'items_retained'=>true,'messages'=>[$message],'done'=>false];
    }

    private static function done(): array {
        return ['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
    }
}
