<?php
defined('ABSPATH') || exit;
status_header(200);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,noarchive">
    <?php wp_head(); ?>
</head>
<body <?php body_class('sn-network-standalone'); ?>>
<?php wp_body_open(); ?>
<main><?php echo do_shortcode('[sabri_network]'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></main>
<?php wp_footer(); ?>
</body>
</html>
