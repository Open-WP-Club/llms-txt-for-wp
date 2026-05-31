<?php

declare(strict_types=1);

namespace LlmsTxt\Output;

use LlmsTxt\Cache\TransientCache;
use LlmsTxt\Content\MultilingualIntegration;
use LlmsTxt\Core\Plugin;
use LlmsTxt\Generator\MarkdownConverter;

/**
 * Serves /llms-full.txt — a concatenation of all enabled posts as full Markdown.
 *
 * This implements the optional llms-full.txt convention from the llms.txt spec,
 * which embeds the complete content of every linked page for AI models that
 * prefer a single downloadable corpus.
 *
 * @package LlmsTxt\Output
 */
final class LlmsFullTxtEndpoint
{
    public function __construct(
        private readonly MarkdownConverter $converter,
        private readonly TransientCache $cache
    ) {}

    /**
     * Register the /llms-full.txt rewrite rule.
     */
    public function registerRewriteRules(): void
    {
        add_rewrite_rule('^llms-full\.txt$', 'index.php?llms_full_txt=1', 'top');
    }

    /**
     * Handle a request for /llms-full.txt.
     */
    public function handleRequest(): void
    {
        if (!get_query_var('llms_full_txt')) {
            return;
        }

        $settings = Plugin::getSettings();

        if (!($settings['enabled'] ?? true)) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'LLMs.txt Generator is disabled.';
            exit;
        }

        /**
         * Fires before llms-full.txt is served.
         *
         * @since 1.3.0
         */
        do_action('llms_full_txt_before_serve');

        $content = $this->generate($settings);

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=3600');

        $customHeaders = apply_filters('llms_full_txt_response_headers', []);
        foreach ($customHeaders as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $content;

        do_action('llms_full_txt_after_serve', $content);

        exit;
    }

    /**
     * Generate (or return cached) llms-full.txt content.
     *
     * @param array<string, mixed> $settings
     */
    private function generate(array $settings): string
    {
        $cached = $this->cache->getFullContent();
        if ($cached !== null) {
            return $cached;
        }

        $postTypes  = $settings['post_types'] ?? ['post', 'page'];
        $limit      = (int) ($settings['posts_per_type'] ?? 100);
        $excluded   = array_map('intval', $settings['excluded_posts'] ?? []);
        $lang       = (string) ($settings['language'] ?? '');

        MultilingualIntegration::switchLanguage($lang);

        $sections = [];
        $queryBase = [
            'post_status'             => 'publish',
            'posts_per_page'          => $limit,
            'orderby'                 => 'date',
            'order'                   => 'DESC',
            'no_found_rows'           => true,
            'update_post_term_cache'  => false,
        ];

        if (!empty($excluded)) {
            $queryBase['post__not_in'] = $excluded;
        }

        foreach ($postTypes as $postType) {
            $queryArgs = array_merge($queryBase, ['post_type' => $postType]);
            $queryArgs = MultilingualIntegration::applyToQueryArgs($queryArgs, $lang);
            $posts     = get_posts($queryArgs);

            foreach ($posts as $post) {
                $md = $this->renderPost($post, $settings);
                if (!empty($md)) {
                    $sections[] = $md;
                }
            }
        }

        MultilingualIntegration::restoreLanguage();

        $content = implode("\n\n---\n\n", $sections);

        /**
         * Filter the complete llms-full.txt output.
         *
         * @since 1.3.0
         *
         * @param string               $content  The assembled content.
         * @param array<string, mixed> $settings Plugin settings.
         */
        $content = apply_filters('llms_full_txt_content', $content, $settings);

        $this->cache->setFullContent($content);

        return $content;
    }

    /**
     * Render a single post as Markdown with metadata header.
     *
     * @param array<string, mixed> $settings
     */
    private function renderPost(\WP_Post $post, array $settings): string
    {
        $parts = [];

        $parts[] = '# ' . get_the_title($post);
        $parts[] = '';

        // Metadata
        $meta = [];
        if ($settings['include_date'] ?? true) {
            $meta[] = '**Published:** ' . get_the_date('Y-m-d', $post);
        }
        if ($settings['include_author'] ?? true) {
            $author = get_the_author_meta('display_name', $post->post_author);
            if (!empty($author)) {
                $meta[] = "**Author:** {$author}";
            }
        }
        $meta[] = '**URL:** ' . (get_permalink($post) ?: '');

        if (!empty($meta)) {
            $parts[] = implode("  \n", $meta);
            $parts[] = '';
        }

        // Content
        $originalPost        = $GLOBALS['post'] ?? null;
        $GLOBALS['post']     = $post;
        setup_postdata($post);

        $html    = apply_filters('the_content', $post->post_content);
        $content = $this->converter->convert($html);

        if ($originalPost instanceof \WP_Post) {
            $GLOBALS['post'] = $originalPost;
            setup_postdata($originalPost);
        } else {
            wp_reset_postdata();
        }

        $parts[] = $content;

        return implode("\n", $parts);
    }
}
