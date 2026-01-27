<?php

declare(strict_types=1);

namespace LlmsTxt\Output;

use LlmsTxt\Core\Plugin;
use LlmsTxt\Generator\MarkdownConverter;
use WP_Post;

/**
 * Handles Markdown output for individual posts.
 *
 * Supports both .md URL extension and Accept: text/markdown header.
 *
 * @package LlmsTxt\Output
 */
final class MarkdownEndpoint
{
    private const ACCEPT_HEADER = 'text/markdown';

    public function __construct(
        private readonly MarkdownConverter $converter
    ) {}

    /**
     * Register rewrite rules for .md extension.
     */
    public function registerRewriteRules(): void
    {
        // We handle .md requests directly in handleRequest() by checking REQUEST_URI
        // This avoids issues with WordPress rewrite rules
    }

    /**
     * Handle Markdown requests.
     */
    public function handleRequest(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // Check for .md extension in URL
        $isMdRequest = (bool) preg_match('/\.md(\?.*)?$/', $requestUri);

        // Check for Accept header
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isAcceptRequest = str_contains($acceptHeader, self::ACCEPT_HEADER);

        if (!$isMdRequest && !$isAcceptRequest) {
            return;
        }

        $settings = Plugin::getSettings();

        // Check if plugin is enabled
        if (!($settings['enabled'] ?? true)) {
            return;
        }

        // Get the post
        $post = $this->getRequestedPost($requestUri, $isMdRequest);

        if ($post === null) {
            if ($isMdRequest) {
                $this->send404();
            }
            return;
        }

        // Check if post type is enabled
        $enabledTypes = $settings['post_types'] ?? ['post', 'page'];
        if (!in_array($post->post_type, $enabledTypes, true)) {
            if ($isMdRequest) {
                $this->send404();
            }
            return;
        }

        /**
         * Fires before markdown content is served.
         *
         * @param WP_Post $post The post being served.
         */
        do_action('llms_txt_before_markdown_serve', $post);

        // Generate markdown
        $markdown = $this->generateMarkdown($post, $settings);

        // Send response
        $this->sendResponse($markdown, $post);
    }

    /**
     * Get the requested post.
     */
    private function getRequestedPost(string $requestUri, bool $isMdRequest): ?WP_Post
    {
        // If it's an Accept header request, use the queried object
        if (!$isMdRequest) {
            $post = get_queried_object();
            if ($post instanceof WP_Post && $post->post_status === 'publish') {
                return $post;
            }
            return null;
        }

        // Extract the path from the URL
        $path = parse_url($requestUri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return null;
        }

        // Remove .md extension and clean up
        $path = preg_replace('/\.md$/', '', $path);
        $path = trim($path, '/');

        if (empty($path)) {
            return null;
        }

        // Get the slug (last segment for posts with date-based permalinks)
        $slug = $path;
        if (str_contains($path, '/')) {
            $segments = explode('/', $path);
            $slug = end($segments);
        }

        // Try to find post by slug first (most common case)
        $posts = get_posts([
            'name' => $slug,
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        if (!empty($posts)) {
            return $posts[0];
        }

        // Try to get page by path (handles hierarchical pages)
        $page = get_page_by_path($path);
        if ($page instanceof WP_Post && $page->post_status === 'publish') {
            return $page;
        }

        return null;
    }

    /**
     * Generate markdown for a post.
     */
    private function generateMarkdown(WP_Post $post, array $settings): string
    {
        $parts = [];

        // Title
        $parts[] = '# ' . get_the_title($post);
        $parts[] = '';

        // Meta info
        $parts[] = $this->buildMetaInfo($post);
        $parts[] = '';

        // Set up post context for shortcodes that depend on global $post
        global $wp_query;
        $originalPost = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        // Process content - apply_filters includes do_shortcode at priority 11
        $content = apply_filters('the_content', $post->post_content);

        // Restore original post context
        if ($originalPost) {
            $GLOBALS['post'] = $originalPost;
            setup_postdata($originalPost);
        } else {
            wp_reset_postdata();
        }

        $parts[] = $this->converter->convert($content);

        // ACF fields if enabled
        if (($settings['include_acf'] ?? true) && function_exists('get_fields')) {
            $acfContent = $this->buildAcfContent($post);
            if (!empty($acfContent)) {
                $parts[] = '';
                $parts[] = '---';
                $parts[] = '';
                $parts[] = '## Additional Information';
                $parts[] = '';
                $parts[] = $acfContent;
            }
        }

        $markdown = implode("\n", $parts);

        /**
         * Filter the markdown output for a post.
         */
        return apply_filters('llms_txt_post_markdown_full', $markdown, $post, $settings);
    }

    /**
     * Build meta information for a post.
     */
    private function buildMetaInfo(WP_Post $post): string
    {
        $lines = [];

        // Date
        $date = get_the_date('Y-m-d', $post);
        $lines[] = "**Published:** {$date}";

        // Author
        $author = get_the_author_meta('display_name', $post->post_author);
        if (!empty($author)) {
            $lines[] = "**Author:** {$author}";
        }

        // Categories
        $categories = get_the_category($post->ID);
        if (!empty($categories)) {
            $catNames = array_map(static fn($cat) => $cat->name, $categories);
            $lines[] = '**Categories:** ' . implode(', ', $catNames);
        }

        // Tags
        $tags = get_the_tags($post->ID);
        if (!empty($tags) && !is_wp_error($tags)) {
            $tagNames = array_map(static fn($tag) => $tag->name, $tags);
            $lines[] = '**Tags:** ' . implode(', ', $tagNames);
        }

        // Original URL
        $url = get_permalink($post);
        $lines[] = "**URL:** {$url}";

        return implode("  \n", $lines);
    }

    /**
     * Build ACF fields content.
     */
    private function buildAcfContent(WP_Post $post): string
    {
        if (!function_exists('get_fields')) {
            return '';
        }

        $fields = get_fields($post->ID);

        if (empty($fields) || !is_array($fields)) {
            return '';
        }

        $lines = [];

        foreach ($fields as $name => $value) {
            $formattedValue = $this->formatAcfValue($value);
            if ($formattedValue !== null) {
                $label = ucwords(str_replace(['_', '-'], ' ', $name));
                $lines[] = "**{$label}:** {$formattedValue}";
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * Format an ACF field value for markdown.
     */
    private function formatAcfValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $clean = wp_strip_all_tags($value);
            return !empty($clean) ? $clean : null;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            if (isset($value[0]) && (is_string($value[0]) || is_numeric($value[0]))) {
                return implode(', ', array_map('strval', $value));
            }

            if (isset($value['url'])) {
                return $value['url'];
            }

            if (isset($value['ID'])) {
                return get_the_title($value['ID']);
            }

            return null;
        }

        if ($value instanceof WP_Post) {
            return get_the_title($value);
        }

        return null;
    }

    /**
     * Send the markdown response.
     */
    private function sendResponse(string $content, WP_Post $post): void
    {
        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Robots-Tag: noindex');

        $filename = sanitize_file_name($post->post_name . '.md');
        header("Content-Disposition: inline; filename=\"{$filename}\"");

        $customHeaders = apply_filters('llms_txt_markdown_response_headers', [], $post);

        foreach ($customHeaders as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $content;

        do_action('llms_txt_after_markdown_serve', $content, $post);

        exit;
    }

    /**
     * Send a 404 response.
     */
    private function send404(): void
    {
        status_header(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Markdown version not available.';
        exit;
    }

    /**
     * Add alternate link tag to head.
     */
    public function addAlternateLink(): void
    {
        if (!is_singular()) {
            return;
        }

        $settings = Plugin::getSettings();

        if (!($settings['enabled'] ?? true)) {
            return;
        }

        $post = get_queried_object();

        if (!$post instanceof WP_Post) {
            return;
        }

        $enabledTypes = $settings['post_types'] ?? ['post', 'page'];
        if (!in_array($post->post_type, $enabledTypes, true)) {
            return;
        }

        $url = rtrim(get_permalink($post), '/') . '.md';

        printf(
            '<link rel="alternate" type="text/markdown" href="%s" title="%s" />' . "\n",
            esc_url($url),
            esc_attr(get_the_title($post) . ' (Markdown)')
        );
    }
}
