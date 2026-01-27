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
 * @package LlmsTxt\Cache
 */
final class TransientCache
{
    private const CACHE_KEY = 'llms_txt_content';
    private const FULL_CACHE_KEY = 'llms_txt_full';

    /**
     * Get cached content.
     *
     * @param bool $full Whether to get full llms.txt or just content parts.
     * @return string|null Cached content or null if not found.
     */
    public function get(bool $full = true): ?string
    {
        $key = $full ? self::FULL_CACHE_KEY : self::CACHE_KEY;
        $cached = get_transient($key);

        return $cached !== false ? (string) $cached : null;
    }

    /**
     * Set cached content.
     *
     * @param string $content Content to cache.
     * @param bool   $full    Whether this is full llms.txt or just content parts.
     */
    public function set(string $content, bool $full = true): void
    {
        $settings = Plugin::getSettings();
        $duration = (int) ($settings['cache_duration'] ?? 3600);
        $key = $full ? self::FULL_CACHE_KEY : self::CACHE_KEY;

        set_transient($key, $content, $duration);
    }

    /**
     * Invalidate all cached content.
     *
     * Called when posts or terms are modified.
     */
    public function invalidate(): void
    {
        delete_transient(self::CACHE_KEY);
        delete_transient(self::FULL_CACHE_KEY);

        /**
         * Fires after llms.txt cache is invalidated.
         *
         * @since 1.0.0
         */
        do_action('llms_txt_cache_invalidated');
    }

    /**
     * Check if cache is valid.
     *
     * @param bool $full Whether to check full llms.txt or just content parts.
     * @return bool True if cache exists and is valid.
     */
    public function isValid(bool $full = true): bool
    {
        return $this->get($full) !== null;
    }

    /**
     * Get cache expiration time.
     *
     * @return int Cache duration in seconds.
     */
    public function getDuration(): int
    {
        $settings = Plugin::getSettings();
        return (int) ($settings['cache_duration'] ?? 3600);
    }

    /**
     * Force regeneration of cache.
     *
     * Invalidates cache and triggers regeneration hook.
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
}
