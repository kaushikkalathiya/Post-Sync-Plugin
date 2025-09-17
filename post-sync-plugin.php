<?php
/**
 * Plugin Name: Post Sync Plugin
 * Description: Sync all posts from Host to Target with HMAC auth and ChatGPT translation.
 * Version: 1.0
 * Author: kaushik
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'include/psp-core.php';
require_once plugin_dir_path(__FILE__) . 'include/psp-admin-assets.php';

// Instantiate the plugin
if (class_exists('Post_Sync_Plugin')) {
    new Post_Sync_Plugin();
}
