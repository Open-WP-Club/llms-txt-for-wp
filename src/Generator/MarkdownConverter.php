<?php

declare(strict_types=1);

namespace LlmsTxt\Generator;

use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Converter\TableConverter;

/**
 * Converts HTML content to Markdown.
 *
 * Wraps the league/html-to-markdown library with WordPress-specific configuration.
 *
 * @package LlmsTxt\Generator
 */
final class MarkdownConverter
{
    private readonly HtmlConverter $converter;

    public function __construct()
    {
        $this->converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'remove_nodes' => 'script style',
            'hard_break' => true,
            'list_item_style' => '-',
        ]);

        // Add table support.
        $this->converter->getEnvironment()->addConverter(new TableConverter());
    }

    /**
     * Convert HTML to Markdown.
     *
     * @param string $html HTML content to convert.
     * @return string Markdown content.
     */
    public function convert(string $html): string
    {
        // Pre-process HTML.
        $html = $this->preProcess($html);

        // Convert to Markdown.
        $markdown = $this->converter->convert($html);

        // Post-process Markdown.
        return $this->postProcess($markdown);
    }

    /**
     * Convert a WordPress post to Markdown.
     *
     * @param \WP_Post $post The post to convert.
     * @param bool     $includeTitle Whether to include title as H1.
     * @return string Markdown content.
     */
    public function convertPost(\WP_Post $post, bool $includeTitle = true): string
    {
        $content = apply_filters('the_content', $post->post_content);
        $markdown = $this->convert($content);

        if ($includeTitle) {
            $title = get_the_title($post);
            $markdown = "# {$title}\n\n{$markdown}";
        }

        /**
         * Filter the Markdown output for a post.
         *
         * @since 1.0.0
         *
         * @param string   $markdown The Markdown content.
         * @param \WP_Post $post     The original post.
         */
        return apply_filters('llms_txt_post_markdown', $markdown, $post);
    }

    /**
     * Pre-process HTML before conversion.
     *
     * @param string $html HTML content.
     * @return string Processed HTML.
     */
    private function preProcess(string $html): string
    {
        // Remove WordPress-specific elements that don't convert well.
        $html = preg_replace('/<div class="wp-block-[^"]*"[^>]*>/', '<div>', $html) ?? $html;

        // Handle Gutenberg blocks.
        $html = $this->processGutenbergBlocks($html);

        // Normalize whitespace.
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;

        return trim($html);
    }

    /**
     * Process Gutenberg blocks for better Markdown output.
     *
     * @param string $html HTML content.
     * @return string Processed HTML.
     */
    private function processGutenbergBlocks(string $html): string
    {
        // Convert Gutenberg code blocks.
        $html = preg_replace_callback(
            '/<pre class="wp-block-code[^"]*"><code>(.+?)<\/code><\/pre>/s',
            static fn(array $matches): string => "\n```\n" . html_entity_decode($matches[1]) . "\n```\n",
            $html
        ) ?? $html;

        // Convert Gutenberg quote blocks.
        $html = preg_replace(
            '/<blockquote class="wp-block-quote[^"]*">/i',
            '<blockquote>',
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * Post-process Markdown after conversion.
     *
     * @param string $markdown Markdown content.
     * @return string Processed Markdown.
     */
    private function postProcess(string $markdown): string
    {
        // Fix multiple consecutive newlines.
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown) ?? $markdown;

        // Ensure proper spacing around headers.
        $markdown = preg_replace('/([^\n])\n(#{1,6} )/', "$1\n\n$2", $markdown) ?? $markdown;

        // Trim each line.
        $lines = explode("\n", $markdown);
        $lines = array_map('rtrim', $lines);
        $markdown = implode("\n", $lines);

        return trim($markdown);
    }

    /**
     * Get the underlying HTML converter instance.
     *
     * @return HtmlConverter
     */
    public function getConverter(): HtmlConverter
    {
        return $this->converter;
    }
}
