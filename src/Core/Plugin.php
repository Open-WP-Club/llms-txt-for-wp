<?php

declare(strict_types=1);

namespace LlmsTxt\Core;

use LlmsTxt\Admin\SettingsPage;
use LlmsTxt\Cache\TransientCache;
use LlmsTxt\Content\ContentAggregator;
use LlmsTxt\Generator\LlmsTxtGenerator;
use LlmsTxt\Generator\MarkdownConverter;
use LlmsTxt\Output\LlmsFullTxtEndpoint;
use LlmsTxt\Output\LlmsTxtEndpoint;
use LlmsTxt\Output\MarkdownEndpoint;

/**
 * Main plugin orchestrator.
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
        $this->cache     = new TransientCache();
        $this->converter = new MarkdownConverter();
        $this->aggregator = new ContentAggregator($this->converter);
        $this->generator  = new LlmsTxtGenerator($this->aggregator, $this->cache);

        // Initialize endpoints.
        $llmsTxtEndpoint     = new LlmsTxtEndpoint($this->generator);
        $markdownEndpoint    = new MarkdownEndpoint($this->converter, $this->cache);
        $llmsFullTxtEndpoint = new LlmsFullTxtEndpoint($this->converter, $this->cache);

        // Initialize admin.
        if (is_admin()) {
            new SettingsPage($this->cache);
            add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        }

        // Register hooks.
        $this->registerHooks($llmsTxtEndpoint, $markdownEndpoint, $llmsFullTxtEndpoint);
    }

    /**
     * Register all WordPress hooks.
     */
    private function registerHooks(
        LlmsTxtEndpoint $llmsTxtEndpoint,
        MarkdownEndpoint $markdownEndpoint,
        LlmsFullTxtEndpoint $llmsFullTxtEndpoint
    ): void {
        // Endpoints
        add_action('init', [$llmsTxtEndpoint, 'registerRewriteRules']);
        add_action('init', [$llmsFullTxtEndpoint, 'registerRewriteRules']);
        add_action('template_redirect', [$llmsTxtEndpoint, 'handleRequest']);
        add_action('template_redirect', [$llmsFullTxtEndpoint, 'handleRequest']);

        // Handle .md requests — 'wp' hook fires after query is set but before template.
        add_action('wp', [$markdownEndpoint, 'handleRequest'], 1);

        // Cache invalidation on content changes.
        add_action('save_post', function (int $postId): void {
            // Skip autosaves and revisions — they don't affect public content.
            if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
                return;
            }
            $this->cache->invalidatePost($postId);
            $this->cache->invalidate();
        }, 10, 1);

        add_action('delete_post', [$this->cache, 'invalidate']);
        add_action('created_term', [$this->cache, 'invalidate']);
        add_action('edited_term', [$this->cache, 'invalidate']);
        add_action('delete_term', [$this->cache, 'invalidate']);

        // WooCommerce: invalidate cache when stock changes programmatically.
        add_action('woocommerce_product_set_stock', [$this->cache, 'invalidate']);
        add_action('woocommerce_variation_set_stock', [$this->cache, 'invalidate']);
        add_action('woocommerce_product_set_stock_status', [$this->cache, 'invalidate']);

        // Add markdown alternate link to head.
        add_action('wp_head', [$markdownEndpoint, 'addAlternateLink']);

        // Query vars.
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'llms_txt';
            $vars[] = 'llms_md';
            $vars[] = 'llms_full_txt';
            return $vars;
        });

        // Add llms.txt reference to robots.txt.
        add_filter('robots_txt', [$this, 'addRobotsTxtEntry'], 10, 2);
    }

    /**
     * Register meta box on post edit screens for enabled post types.
     */
    public function registerMetaBox(): void
    {
        $settings = self::getSettings();
        if (!($settings['enabled'] ?? true)) {
            return;
        }

        foreach ($settings['post_types'] ?? ['post', 'page'] as $postType) {
            add_meta_box(
                'llms_txt_markdown_link',
                __('LLMs.txt', 'llms-txt-generator'),
                [$this, 'renderMetaBox'],
                $postType,
                'side',
                'low'
            );
        }
    }

    /**
     * Render the meta box content on a post edit screen.
     */
    public function renderMetaBox(\WP_Post $post): void
    {
        if ($post->post_status !== 'publish') {
            echo '<p>' . esc_html__('Publish this post to view it as Markdown.', 'llms-txt-generator') . '</p>';
            return;
        }

        $mdUrl = rtrim(get_permalink($post) ?: '', '/') . '.md';
        printf(
            '<p><a href="%s" target="_blank" class="button button-secondary" style="width:100%%;text-align:center;">%s</a></p>',
            esc_url($mdUrl),
            esc_html__('View as Markdown', 'llms-txt-generator')
        );
    }

    /**
     * Add llms.txt and llms-full.txt entries to robots.txt.
     */
    public function addRobotsTxtEntry(string $output, bool $public): string
    {
        if (!$public) {
            return $output;
        }

        $settings = self::getSettings();

        if (!($settings['enabled'] ?? true) || !($settings['robots_txt_entry'] ?? true)) {
            return $output;
        }

        $output .= "\n# LLMs.txt - AI-friendly content index\n";
        $output .= 'Llms-Txt: ' . home_url('/llms.txt') . "\n";
        $output .= 'Llms-Txt-Full: ' . home_url('/llms-full.txt') . "\n";

        return $output;
    }

    /**
     * Plugin activation.
     */
    public static function activate(): void
    {
        add_option('llms_txt_flush_rewrite', true);

        if (!get_option('llms_txt_settings')) {
            add_option('llms_txt_settings', self::getDefaults());
        }
    }

    /**
     * Plugin deactivation.
     */
    public static function deactivate(): void
    {
        delete_transient('llms_txt_content');
        delete_transient('llms_txt_full');
        delete_transient('llms_txt_full_content');
        flush_rewrite_rules();
    }

    /**
     * Get default plugin settings.
     *
     * Single source of truth — used by activate(), getSettings(), and SettingsPage.
     *
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        return [
            'enabled'              => true,
            'post_types'           => ['post', 'page'],
            'taxonomies'           => ['category', 'post_tag'],
            'posts_per_type'       => 100,
            'include_acf'          => true,
            'include_meta'         => true,
            'include_author'       => true,
            'include_date'         => true,
            'include_categories'   => true,
            'include_tags'         => true,
            'excluded_posts'       => [],
            'excluded_categories'  => [],
            'min_content_length'   => 0,
            'robots_txt_entry'     => true,
            'custom_header'        => '',
            'custom_description'   => '',
            'link_descriptions'    => true,
            'cache_duration'       => 86400,
            'wc_include_price'     => true,
            'wc_include_stock'     => true,
            'wc_include_attributes' => true,
            'wc_include_rating'    => true,
            'language'             => '',
        ];
    }

    /**
     * Get plugin settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $settings = get_option('llms_txt_settings', []);
        return array_merge(self::getDefaults(), is_array($settings) ? $settings : []);
    }
}
