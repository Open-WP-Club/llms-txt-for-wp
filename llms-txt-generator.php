<?php
/**
 * Plugin Name:       LLMs.txt Generator
 * Plugin URI:        https://github.com/open-wp-club/llms-txt-for-wp
 * Description:       Generate llms.txt and Markdown versions of your WordPress content for AI/LLM consumption.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Openwpclub.com
 * Author URI:        https://openwpclub.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       llms-txt-generator
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package LlmsTxt
 */

declare(strict_types=1);

namespace LlmsTxt;

use LlmsTxt\Core\Plugin;

// Prevent direct access.
defined('ABSPATH') || exit;

// Plugin constants.
define('LLMS_TXT_VERSION', '1.3.0');
define('LLMS_TXT_FILE', __FILE__);
define('LLMS_TXT_PATH', plugin_dir_path(__FILE__));
define('LLMS_TXT_URL', plugin_dir_url(__FILE__));
define('LLMS_TXT_BASENAME', plugin_basename(__FILE__));

// Autoloader.
if (file_exists(LLMS_TXT_PATH . 'vendor/autoload.php')) {
    require_once LLMS_TXT_PATH . 'vendor/autoload.php';
}

/**
 * Initialize the plugin.
 *
 * @return Plugin
 */
function llms_txt(): Plugin
{
    static $plugin = null;

    if ($plugin === null) {
        $plugin = new Plugin();
    }

    return $plugin;
}

// Boot the plugin.
add_action('plugins_loaded', static function (): void {
    // Check PHP version.
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        add_action('admin_notices', static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'LLMs.txt Generator requires PHP 8.2 or higher. Please upgrade your PHP version.',
                    'llms-txt-generator'
                )
            );
        });
        return;
    }

    // Check if autoloader exists.
    if (!file_exists(LLMS_TXT_PATH . 'vendor/autoload.php')) {
        add_action('admin_notices', static function (): void {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__(
                    'LLMs.txt Generator requires Composer dependencies. Please run "composer install" in the plugin directory.',
                    'llms-txt-generator'
                )
            );
        });
        return;
    }

    llms_txt()->boot();
});

// Activation hook.
register_activation_hook(__FILE__, static function (): void {
    if (class_exists(Plugin::class)) {
        Plugin::activate();
    }
});

// Deactivation hook.
register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(Plugin::class)) {
        Plugin::deactivate();
    }
});
