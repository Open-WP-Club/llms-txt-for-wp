<?php
/**
 * Uninstall script for LLMs.txt Generator.
 *
 * Removes all plugin data when the plugin is uninstalled.
 *
 * @package LlmsTxt
 */

declare(strict_types=1);

// Exit if not called by WordPress.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options.
delete_option('llms_txt_settings');
delete_option('llms_txt_flush_rewrite');

// Delete transients.
delete_transient('llms_txt_content');
delete_transient('llms_txt_full');

// Flush rewrite rules.
flush_rewrite_rules();
