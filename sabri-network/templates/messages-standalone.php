<?php
defined('ABSPATH') || exit;
$mode = sanitize_key((string) get_query_var('sn_messages_mode'));
if (!in_array($mode, ['messages', 'settings'], true)) {
    $mode = 'messages';
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('sabri-messages-standalone'); ?>>
<?php wp_body_open(); ?>
<?php echo $mode === 'settings' ? SN_Messages::render_settings() : SN_Messages::render_messages(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<?php wp_footer(); ?>
</body>
</html>
