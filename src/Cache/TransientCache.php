<?php

declare(strict_types=1);

namespace LlmsTxt\Cache;

use LlmsTxt\Core\Plugin;

/**
 * Transient-based caching for llms.txt content.
 *
 * Uses WordPress Transients API for caching generated content.
 * Automatically integrates with object cache if available.
 *
 * Three cache layers:
 *   - Full llms.txt index  (llms_txt_full)
 *   - Content sections     (llms_txt_content)
 *   - Per-post Markdown    (llms_txt_post_{id})
 *   - llms-full.txt corpus (llms_txt_full_content)
 *
 * @package LlmsTxt\Cache
 */
final class TransientCache
{
    private const CACHE_KEY          = 'llms_txt_content';
    private const FULL_CACHE_KEY     = 'llms_txt_full';
    private const FULL_CONTENT_KEY   = 'llms_txt_full_content';
    private const POST_CACHE_PREFIX  = 'llms_txt_post_';

    // -------------------------------------------------------------------------
    // llms.txt index cache
    // -------------------------------------------------------------------------

    /**
     * Get cached llms.txt content.
     *
     * @param bool $full Whether to get the full assembled file or just content sections.
     * @return string|null Cached content or null if not found.
     */
    public function get(bool $full = true): ?string
    {
        $key    = $full ? self::FULL_CACHE_KEY : self::CACHE_KEY;
        $cached = get_transient($key);

        return $cached !== false ? (string) $cached : null;
    }

    /**
     * Set cached llms.txt content.
     *
     * @param string $content Content to cache.
     * @param bool   $full    Whether this is the full assembled file.
     */
    public function set(string $content, bool $full = true): void
    {
        $key = $full ? self::FULL_CACHE_KEY : self::CACHE_KEY;
        set_transient($key, $content, $this->getDuration());
    }

    /**
     * Check if cache is valid.
     *
     * @param bool $full Whether to check full file or content sections.
     */
    public function isValid(bool $full = true): bool
    {
        return $this->get($full) !== null;
    }

    // -------------------------------------------------------------------------
    // Per-post Markdown cache
    // -------------------------------------------------------------------------

    /**
     * Get cached Markdown for a single post.
     */
    public function getPost(int $postId): ?string
    {
        $cached = get_transient(self::POST_CACHE_PREFIX . $postId);
        return $cached !== false ? (string) $cached : null;
    }

    /**
     * Cache Markdown for a single post.
     */
    public function setPost(int $postId, string $content): void
    {
        set_transient(self::POST_CACHE_PREFIX . $postId, $content, $this->getDuration());
    }

    /**
     * Invalidate the Markdown cache for a specific post.
     *
     * Also clears the llms-full.txt cache since that corpus contains this post.
     */
    public function invalidatePost(int $postId): void
    {
        delete_transient(self::POST_CACHE_PREFIX . $postId);
        delete_transient(self::FULL_CONTENT_KEY);
    }

    // -------------------------------------------------------------------------
    // llms-full.txt corpus cache
    // -------------------------------------------------------------------------

    /**
     * Get cached llms-full.txt corpus.
     */
    public function getFullContent(): ?string
    {
        $cached = get_transient(self::FULL_CONTENT_KEY);
        return $cached !== false ? (string) $cached : null;
    }

    /**
     * Cache the llms-full.txt corpus.
     */
    public function setFullContent(string $content): void
    {
        set_transient(self::FULL_CONTENT_KEY, $content, $this->getDuration());
    }

    // -------------------------------------------------------------------------
    // Global invalidation
    // -------------------------------------------------------------------------

    /**
     * Invalidate all llms.txt-related caches (index + full corpus).
     *
     * Per-post caches are invalidated separately via invalidatePost().
     */
    public function invalidate(): void
    {
        delete_transient(self::CACHE_KEY);
        delete_transient(self::FULL_CACHE_KEY);
        delete_transient(self::FULL_CONTENT_KEY);

        /**
         * Fires after llms.txt cache is invalidated.
         *
         * @since 1.0.0
         */
        do_action('llms_txt_cache_invalidated');
    }

    /**
     * Force regeneration of cache.
     */
    public function forceRegenerate(): void
    {
        $this->invalidate();

        /**
         * Fires when cache regeneration is forced.
         *
         * @since 1.0.0
         */
        do_action('llms_txt_force_regenerate');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the configured cache duration in seconds.
     */
    public function getDuration(): int
    {
        $settings = Plugin::getSettings();
        return (int) ($settings['cache_duration'] ?? 86400);
    }
}
