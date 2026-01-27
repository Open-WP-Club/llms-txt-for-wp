<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

/**
 * Represents a single content item in the llms.txt file.
 *
 * Uses readonly properties for immutability.
 *
 * @package LlmsTxt\Content
 */
final readonly class ContentItem
{
    /**
     * @param string      $title       Item title.
     * @param string      $url         Item URL.
     * @param string|null $description Optional description.
     * @param array       $metadata    Optional additional metadata.
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?string $description = null,
        public array $metadata = []
    ) {}

    /**
     * Convert to llms.txt format.
     *
     * Format: - [Title](url): Description
     *
     * @return string Formatted line for llms.txt.
     */
    public function toLine(): string
    {
        // Get clean URL (add .md extension for markdown).
        $url = $this->getMarkdownUrl();

        $line = "- [{$this->title}]({$url})";

        if ($this->description !== null && $this->description !== '') {
            $line .= ": {$this->description}";
        }

        return $line;
    }

    /**
     * Get the Markdown URL for this item.
     *
     * @return string URL with .md extension.
     */
    public function getMarkdownUrl(): string
    {
        // Check if URL ends with a trailing slash.
        $url = rtrim($this->url, '/');

        // Don't add .md if URL already has it.
        if (str_ends_with($url, '.md')) {
            return $url;
        }

        return $url . '.md';
    }

    /**
     * Create from a WordPress post.
     *
     * @param \WP_Post    $post        The post.
     * @param string|null $description Optional description.
     * @return self
     */
    public static function fromPost(\WP_Post $post, ?string $description = null): self
    {
        return new self(
            title: get_the_title($post),
            url: get_permalink($post) ?: '',
            description: $description,
            metadata: [
                'post_id' => $post->ID,
                'post_type' => $post->post_type,
                'post_date' => $post->post_date,
            ]
        );
    }

    /**
     * Create from a WordPress term.
     *
     * @param \WP_Term    $term        The term.
     * @param string|null $description Optional description.
     * @return self|null Null if term link is invalid.
     */
    public static function fromTerm(\WP_Term $term, ?string $description = null): ?self
    {
        $url = get_term_link($term);

        if (is_wp_error($url)) {
            return null;
        }

        return new self(
            title: $term->name,
            url: $url,
            description: $description ?? ($term->description ?: null),
            metadata: [
                'term_id' => $term->term_id,
                'taxonomy' => $term->taxonomy,
            ]
        );
    }
}
