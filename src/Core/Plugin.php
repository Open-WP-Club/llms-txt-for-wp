<?php

declare(strict_types=1);

namespace LlmsTxt\Core;

use LlmsTxt\Admin\SettingsPage;
use LlmsTxt\Cache\TransientCache;
use LlmsTxt\Content\ContentAggregator;
use LlmsTxt\Generator\LlmsTxtGenerator;
use LlmsTxt\Generator\MarkdownConverter;
use LlmsTxt\Output\LlmsTxtEndpoint;
use LlmsTxt\Output\MarkdownEndpoint;

/**
 * Main plugin orchestrator.
 *
 * Uses constructor property promotion and readonly properties for immutability.
 *
 * @package LlmsTxt\Core
 */
final class Plugin
{
    private bool $booted = false;

    private readonly TransientCache $cache;
    private readonly MarkdownConverter $converter;
    private readonly ContentAggregator $aggregator;
    private readonly LlmsTxtGenerator $generator;

    /**
     * Boot the plugin and initialize all components.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        // Initialize core services.
        $this->cache = new TransientCache();
        $this->converter = new MarkdownConverter();
        $this->aggregator = new ContentAggregator($this->converter);
        $this->generator = new LlmsTxtGenerator($this->aggregator, $this->cache);

        // Initialize endpoints.
        $llmsTxtEndpoint = new LlmsTxtEndpoint($this->generator);
        $markdownEndpoint = new MarkdownEndpoint($this->converter);

        // Initialize admin.
        if (is_admin()) {
            new SettingsPage($this->cache);
        }

        // Register hooks.
        $this->registerHooks($llmsTxtEndpoint, $markdownEndpoint);
    }

    /**
     * Register all WordPress hooks.
     */
    private function registerHooks(
        LlmsTxtEndpoint $llmsTxtEndpoint,
        MarkdownEndpoint $markdownEndpoint
    ): void {
        // Endpoints
        add_action('init', [$llmsTxtEndpoint, 'registerRewriteRules']);
        add_action('template_redirect', [$llmsTxtEndpoint, 'handleRequest']);

        // Handle .md requests - use 'wp' hook which fires after query is set but before template
        add_action('wp', [$markdownEndpoint, 'handleRequest'], 1);

        // Cache invalidation on content changes.
        add_action('save_post', [$this->cache, 'invalidate']);
        add_action('delete_post', [$this->cache, 'invalidate']);
        add_action('created_term', [$this->cache, 'invalidate']);
        add_action('edited_term', [$this->cache, 'invalidate']);
        add_action('delete_term', [$this->cache, 'invalidate']);

        // Add markdown alternate link to head.
        add_action('wp_head', [$markdownEndpoint, 'addAlternateLink']);

        // Query vars.
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'llms_txt';
            $vars[] = 'llms_md';
            return $vars;
        });
    }

    /**
     * Plugin activation.
     */
    public static function activate(): void
    {
        // Flush rewrite rules on activation.
        add_option('llms_txt_flush_rewrite', true);

        // Set default options.
        $defaults = [
            'enabled' => true,
            'post_types' => ['post', 'page'],
            'taxonomies' => ['category', 'post_tag'],
            'posts_per_type' => 100,
            'include_acf' => true,
            'include_meta' => true,
            'custom_header' => '',
            'custom_description' => '',
            'link_descriptions' => true,
            'cache_duration' => 86400,
        ];

        if (!get_option('llms_txt_settings')) {
            add_option('llms_txt_settings', $defaults);
        }
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate(): void
    {
        // Clear cache.
        delete_transient('llms_txt_content');
        delete_transient('llms_txt_full');

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Get plugin settings.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $defaults = [
            'enabled' => true,
            'post_types' => ['post', 'page'],
            'taxonomies' => ['category', 'post_tag'],
            'posts_per_type' => 100,
            'include_acf' => true,
            'include_meta' => true,
            'custom_header' => '',
            'custom_description' => '',
            'link_descriptions' => true,
            'cache_duration' => 86400,
        ];

        $settings = get_option('llms_txt_settings', []);

        return array_merge($defaults, is_array($settings) ? $settings : []);
    }
}
