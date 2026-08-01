<?php
defined('ABSPATH') || exit;

final class SN_Admin {
    public static function register_menu(): void {
        add_menu_page('Sabri Network and Messages', 'Network', 'manage_options', 'sabri-network', [self::class, 'render_page'], 'dashicons-format-chat', 58);
    }

    public static function register_settings(): void {
        add_action('admin_post_sn_repair_network', [self::class, 'repair_network']);
        register_setting('sn_settings', 'sn_menu_label', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sn_settings', 'sn_default_country_code', ['sanitize_callback' => [self::class, 'sanitize_country_code']]);
        register_setting('sn_settings', 'sn_max_upload_mb', ['sanitize_callback' => [self::class, 'sanitize_upload_limit']]);
        register_setting('sn_settings', 'sn_stun_urls', ['sanitize_callback' => [self::class, 'sanitize_stun_urls']]);
    }

    public static function repair_network(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not permitted to repair Network.', 'sabri-network'));
        }
        check_admin_referer('sn_repair_network');
        SN_Activator::retire_legacy_secrets();
        SN_DB::install();
        SN_Private_Files::ensure_storage();
        SN_Activator::ensure_network_page(true);
        update_option('sn_plugin_version', SN_VERSION, false);
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        do_action('litespeed_purge_all');
        flush_rewrite_rules(false);
        SN_DB::audit('system_repair', 'plugin', 17);
        wp_safe_redirect(add_query_arg(['page' => 'sabri-network', 'sn_repaired' => 1], admin_url('admin.php')));
        exit;
    }

    public static function sanitize_country_code(string $value): string {
        $value = preg_replace('/[^\d+]/', '', wp_unslash($value));
        return preg_match('/^\+[1-9]\d{0,3}$/', $value) ? $value : '+92';
    }

    public static function sanitize_upload_limit($value): int {
        return min(100, max(1, absint($value)));
    }

    public static function sanitize_stun_urls(string $value): string {
        $clean = [];
        foreach ((array) preg_split('/\r\n|\r|\n/', wp_unslash($value)) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/^(stun|stuns):[^\s]+$/i', $line)) {
                $clean[] = $line;
            }
        }
        return implode("\n", array_values(array_unique($clean)));
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $tables = ['conversations', 'members', 'messages', 'reactions', 'contacts', 'updates', 'update_views', 'calls', 'call_members', 'signals', 'notifications', 'blocks', 'reports', 'attachments', 'rate_limits', 'audit_log'];
        $missing = [];
        foreach ($tables as $table) {
            $full = SN_DB::table($table);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) !== $full) {
                $missing[] = $table;
            }
        }
        $count = static function (string $table, string $where = '') use ($wpdb): int {
            $full = SN_DB::table($table);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $full)) !== $full) {
                return 0;
            }
            $allowed = ['', "WHERE status='open'", "WHERE attachment_id>0 AND attachment_source='legacy_wp'", "WHERE media_id>0 AND media_source='legacy_wp'"];
            if (!in_array($where, $allowed, true)) {
                return 0;
            }
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM $full $where"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        };
        $page_id = (int) get_option('sn_network_page_id');
        $page_ok = $page_id && SN_Activator::is_owned_page($page_id) && get_post_status($page_id) === 'publish' && has_shortcode((string) get_post_field('post_content', $page_id), 'sabri_network');
        $identity_ready = SN_Policy::identity_authority_available();
        $notification_adapter = has_filter('sn_network_notification_handled');
        $scanner = has_filter('sn_network_attachment_scan_result');
        $storage = SN_Private_Files::ensure_storage();
        $legacy_messages = $count('messages', "WHERE attachment_id>0 AND attachment_source='legacy_wp'");
        $legacy_updates = $count('updates', "WHERE media_id>0 AND media_source='legacy_wp'");
        ?>
        <div class="wrap sn-admin-wrap">
            <h1>Sabri Network and Messages <small style="font-size:14px;color:#646970">Version <?php echo esc_html(SN_VERSION); ?></small></h1>
            <p>File 17 is the canonical owner of relationships, conversations, messages and call state. Authentication remains with File 00/File 02; notification delivery remains with File 19; global navigation remains with File 20.</p>
            <?php $repaired = isset($_GET['sn_repaired']) ? absint(wp_unslash($_GET['sn_repaired'])) : 0; ?>
            <?php if ($repaired === 1): ?>
                <div class="notice notice-success is-dismissible"><p>Network repair completed. Database, owned page, private storage and rewrite rules were rechecked.</p></div>
            <?php endif; ?>

            <h2>System status</h2>
            <table class="widefat striped" style="max-width:1000px"><tbody>
                <tr><th>Owned Network page</th><td><?php echo $page_ok ? '✅ Ready' : '❌ Repair required'; ?></td></tr>
                <tr><th>Database schema <?php echo esc_html(SN_DB::DB_VERSION); ?></th><td><?php echo empty($missing) ? '✅ Ready' : '❌ Missing: ' . esc_html(implode(', ', $missing)); ?></td></tr>
                <tr><th>Private attachment storage</th><td><?php echo $storage ? '✅ Writable and outside the public web root' : '❌ Unavailable or unsafe location'; ?></td></tr>
                <tr><th>Attachment malware scanner</th><td><?php echo $scanner ? '✅ Adapter connected' : '⚠️ Document uploads fail closed until an approved scanner adapter is connected'; ?></td></tr>
                <tr><th>File 00 / File 02 identity adapter</th><td><?php echo $identity_ready ? '✅ Available' : '⚠️ Integration not confirmed'; ?></td></tr>
                <tr><th>File 19 notification adapter</th><td><?php echo $notification_adapter ? '✅ Connected' : '⚠️ Local privacy-safe fallback queue active'; ?></td></tr>
                <tr><th>Legacy public message attachments</th><td><?php echo esc_html((string) $legacy_messages); ?> — withheld until controlled migration</td></tr>
                <tr><th>Legacy public update media</th><td><?php echo esc_html((string) $legacy_updates); ?> — withheld until controlled migration</td></tr>
            </tbody></table>

            <h2>Operational counts</h2>
            <table class="widefat striped" style="max-width:1000px"><tbody>
                <tr><th>Conversations</th><td><?php echo esc_html((string) $count('conversations')); ?></td></tr>
                <tr><th>Messages</th><td><?php echo esc_html((string) $count('messages')); ?></td></tr>
                <tr><th>Calls</th><td><?php echo esc_html((string) $count('calls')); ?></td></tr>
                <tr><th>Open reports</th><td><?php echo esc_html((string) $count('reports', "WHERE status='open'")); ?></td></tr>
            </tbody></table>

            <h2>Safe settings</h2>
            <form method="post" action="options.php" style="max-width:1000px">
                <?php settings_fields('sn_settings'); ?>
                <table class="form-table">
                    <tr><th><label for="sn_menu_label">Route label</label></th><td><input class="regular-text" id="sn_menu_label" name="sn_menu_label" value="<?php echo esc_attr((string) get_option('sn_menu_label', 'Network')); ?>"><p class="description">File 20 owns global navigation; this label is provided through the integration contract only.</p></td></tr>
                    <tr><th><label for="sn_default_country_code">Default country code</label></th><td><input id="sn_default_country_code" name="sn_default_country_code" value="<?php echo esc_attr((string) get_option('sn_default_country_code', '+92')); ?>"></td></tr>
                    <tr><th><label for="sn_max_upload_mb">Private upload limit (MB)</label></th><td><input type="number" min="1" max="100" id="sn_max_upload_mb" name="sn_max_upload_mb" value="<?php echo esc_attr((string) get_option('sn_max_upload_mb', 25)); ?>"></td></tr>
                    <tr><th><label for="sn_stun_urls">STUN URLs</label></th><td><textarea class="large-text code" rows="4" id="sn_stun_urls" name="sn_stun_urls"><?php echo esc_textarea((string) get_option('sn_stun_urls', '')); ?></textarea><p class="description">TURN credentials must be short-lived and supplied by <code>sn_network_ephemeral_turn_credentials</code>. Long-lived credentials are never stored here.</p></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Repair</h2>
            <p>Repair is non-destructive: it repairs only the File-17-owned page and never overwrites an unrelated <code>/network/</code> page.</p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="sn_repair_network">
                <?php wp_nonce_field('sn_repair_network'); ?>
                <?php submit_button('Run Complete Repair', 'secondary'); ?>
            </form>
            <p><a href="<?php echo esc_url(SN_Activator::network_url()); ?>">Open Network</a> · <a href="<?php echo esc_url(SN_Activator::safe_url()); ?>">Open Safe Route</a></p>
        </div>
        <?php
    }
}
