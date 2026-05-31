<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

use LlmsTxt\Core\Plugin;
use LlmsTxt\Generator\MarkdownConverter;

/**
 * Aggregates content from various WordPress sources.
 *
 * Collects posts, pages, custom post types, taxonomies, and ACF fields
 * to build the complete llms.txt content.
 *
 * @package LlmsTxt\Content
 */
final class ContentAggregator
{
    /**
     * @param MarkdownConverter $converter Markdown converter instance.
     */
    public function __construct(
        private readonly MarkdownConverter $converter
    ) {}

    /**
     * Aggregate all content for llms.txt.
     *
     * @return ContentCollection Collection of all content items.
     */
    public function aggregate(): ContentCollection
    {
        $settings = Plugin::getSettings();
        $collection = new ContentCollection();

        $postTypes          = $settings['post_types'] ?? ['post', 'page'];
        $limit              = (int) ($settings['posts_per_type'] ?? 100);
        $includeDescriptions = (bool) ($settings['link_descriptions'] ?? true);
        $excludedPosts      = $settings['excluded_posts'] ?? [];
        $excludedCategories = $settings['excluded_categories'] ?? [];
        $minContentLength   = (int) ($settings['min_content_length'] ?? 0);
        $lang               = (string) ($settings['language'] ?? '');

        // Apply language switch for WPML before running queries.
        MultilingualIntegration::switchLanguage($lang);

        // Collect post types.
        foreach ($postTypes as $postType) {
            $items = $this->collectPostType(
                $postType,
                $limit,
                $includeDescriptions,
                $excludedPosts,
                $excludedCategories,
                $minContentLength,
                $lang
            );
            $collection->addSection($this->getPostTypeLabel($postType), $items);
        }

        // Collect taxonomies.
        $taxonomies = $settings['taxonomies'] ?? [];
        foreach ($taxonomies as $taxonomy) {
            $items = $this->collectTaxonomy($taxonomy, $includeDescriptions, $lang);
            if (!empty($items)) {
                $collection->addSection($this->getTaxonomyLabel($taxonomy), $items);
            }
        }

        // Restore language after all queries are done.
        MultilingualIntegration::restoreLanguage();

        /**
         * Filter the aggregated content collection.
         *
         * @since 1.0.0
         *
         * @param ContentCollection    $collection The content collection.
         * @param array<string, mixed> $settings   Plugin settings.
         */
        return apply_filters('llms_txt_content_collection', $collection, $settings);
    }

    /**
     * Collect items from a post type.
     *
     * @param string   $postType            Post type slug.
     * @param int      $limit               Maximum number of posts.
     * @param bool     $includeDescriptions Whether to include descriptions.
     * @param array    $excludedPosts       Post IDs to exclude.
     * @param array    $excludedCategories  Category IDs to exclude.
     * @param int      $minContentLength    Minimum content length.
     * @param string   $lang                Language code for Polylang (empty = all).
     * @return ContentItem[] Array of content items.
     */
    private function collectPostType(
        string $postType,
        int $limit,
        bool $includeDescriptions,
        array $excludedPosts = [],
        array $excludedCategories = [],
        int $minContentLength = 0,
        string $lang = ''
    ): array {
        $queryArgs = [
            'post_type'              => $postType,
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => $includeDescriptions,
            'update_post_term_cache' => !empty($excludedCategories),
        ];

        if (!empty($excludedPosts)) {
            $queryArgs['post__not_in'] = array_map('intval', $excludedPosts);
        }

        if (!empty($excludedCategories) && is_object_in_taxonomy($postType, 'category')) {
            $queryArgs['category__not_in'] = array_map('intval', $excludedCategories);
        }

        // Polylang language filter.
        $queryArgs = MultilingualIntegration::applyToQueryArgs($queryArgs, $lang);

        $posts = get_posts($queryArgs);
        $items = [];

        foreach ($posts as $post) {
            if ($minContentLength > 0) {
                $contentLength = mb_strlen(wp_strip_all_tags($post->post_content));
                if ($contentLength < $minContentLength) {
                    continue;
                }
            }

            $description = $includeDescriptions
                ? $this->getPostDescription($post)
                : null;

            $items[] = new ContentItem(
                title: get_the_title($post),
                url: get_permalink($post) ?: '',
                description: $description
            );
        }

        return $items;
    }

    /**
     * Get description for a post.
     *
     * Priority: WooCommerce short description → Elementor content → SEO meta → excerpt → generated.
     *
     * @param \WP_Post $post The post.
     * @return string|null Description or null.
     */
    private function getPostDescription(\WP_Post $post): ?string
    {
        $settings = Plugin::getSettings();

        // WooCommerce: short description takes priority for products.
        if ($post->post_type === 'product' && WooCommerceIntegration::isActive()) {
            $wooDesc = WooCommerceIntegration::getProductDescription($post);
            if ($wooDesc !== null) {
                return $this->truncateDescription($wooDesc);
            }
        }

        // Page builders that store content outside post_content (Elementor, Bricks, Oxygen, Thrive, SiteOrigin).
        $builderText = PageBuilderIntegration::extractPageBuilderText($post);
        if ($builderText !== null) {
            return $this->truncateDescription($builderText);
        }

        // Try Yoast SEO meta description.
        if ($settings['include_meta'] ?? true) {
            $metaDesc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
            if (!empty($metaDesc)) {
                return $this->truncateDescription((string) $metaDesc);
            }

            // Try Rank Math.
            $rankMathDesc = get_post_meta($post->ID, 'rank_math_description', true);
            if (!empty($rankMathDesc)) {
                return $this->truncateDescription((string) $rankMathDesc);
            }

            // Try All in One SEO.
            $aioseoDesc = get_post_meta($post->ID, '_aioseo_description', true);
            if (!empty($aioseoDesc)) {
                return $this->truncateDescription((string) $aioseoDesc);
            }
        }

        // Try post excerpt.
        if (!empty($post->post_excerpt)) {
            $excerpt = $this->processContent($post->post_excerpt, $post);
            if (!empty(trim($excerpt))) {
                return $this->truncateDescription($excerpt);
            }
        }

        // Generate from content.
        $content = $this->processContent($post->post_content, $post);
        if (!empty($content)) {
            return $this->truncateDescription($content);
        }

        return null;
    }

    /**
     * Process content by executing shortcodes, blocks, and converting to plain text.
     *
     * @param string   $content The content to process.
     * @param \WP_Post $post    The post context.
     * @return string Processed plain text content.
     */
    private function processContent(string $content, \WP_Post $post): string
    {
        if (empty($content)) {
            return '';
        }

        $originalPost = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        if (function_exists('do_blocks')) {
            $content = do_blocks($content);
        }

        $processed = do_shortcode($content);
        $processed = wptexturize($processed);
        $processed = convert_smilies($processed);
        $processed = wp_filter_content_tags($processed);

        if ($originalPost) {
            $GLOBALS['post'] = $originalPost;
            setup_postdata($originalPost);
        } else {
            wp_reset_postdata();
        }

        $processed = wp_strip_all_tags($processed);
        $processed = html_entity_decode($processed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $processed = preg_replace('/\[[^\]]+\]/', '', $processed) ?? $processed;
        $processed = preg_replace('/\s+/', ' ', $processed) ?? $processed;

        return trim($processed);
    }

    /**
     * Truncate description to reasonable length.
     *
     * @param string $text      Text to truncate.
     * @param int    $maxLength Maximum length.
     * @return string Truncated text.
     */
    private function truncateDescription(string $text, int $maxLength = 160): string
    {
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $text     = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($text, ' ');

        if ($lastSpace !== false && $lastSpace > $maxLength - 30) {
            $text = mb_substr($text, 0, $lastSpace);
        }

        return $text . '...';
    }

    /**
     * Collect items from a taxonomy.
     *
     * @param string $taxonomy            Taxonomy slug.
     * @param bool   $includeDescriptions Whether to include descriptions.
     * @param string $lang                Language code for Polylang (empty = all).
     * @return ContentItem[] Array of content items.
     */
    private function collectTaxonomy(string $taxonomy, bool $includeDescriptions, string $lang = ''): array
    {
        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => 50,
        ];

        $args  = MultilingualIntegration::applyToQueryArgs($args, $lang);
        $terms = get_terms($args);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $items = [];

        foreach ($terms as $term) {
            $description = $includeDescriptions && !empty($term->description)
                ? $this->truncateDescription($term->description)
                : null;

            $url = get_term_link($term);
            if (is_wp_error($url)) {
                continue;
            }

            $items[] = new ContentItem(
                title: $term->name,
                url: $url,
                description: $description
            );
        }

        return $items;
    }

    /**
     * Get human-readable label for a post type.
     */
    private function getPostTypeLabel(string $postType): string
    {
        $postTypeObj = get_post_type_object($postType);

        if ($postTypeObj === null) {
            return ucfirst($postType);
        }

        return $postTypeObj->labels->name ?? ucfirst($postType);
    }

    /**
     * Get human-readable label for a taxonomy.
     */
    private function getTaxonomyLabel(string $taxonomy): string
    {
        $taxonomyObj = get_taxonomy($taxonomy);

        if ($taxonomyObj === false) {
            return ucfirst($taxonomy);
        }

        return $taxonomyObj->labels->name ?? ucfirst($taxonomy);
    }

    /**
     * Get Markdown converter.
     */
    public function getConverter(): MarkdownConverter
    {
        return $this->converter;
    }
}
