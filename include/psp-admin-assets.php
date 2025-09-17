<?php

if (!defined('ABSPATH')) {
    exit;
}

function psp_admin_enqueue_assets($hook_suffix) {
    // Only load for admin users
    if (!current_user_can('manage_options')) return;

    // Only load on our plugin settings page (top-level slug 'post-sync')
    if ($hook_suffix !== 'toplevel_page_post-sync') return;

    // Compute plugin root URL from this file's directory
    $plugin_root = plugin_dir_url(dirname(__FILE__));
    wp_enqueue_script('psp-admin-script', $plugin_root . 'assets/js/psp_script.js', array('jquery'), '0.1.0', true);
}
add_action('admin_enqueue_scripts', 'psp_admin_enqueue_assets');
