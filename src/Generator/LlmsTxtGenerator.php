<?php

declare(strict_types=1);

namespace LlmsTxt\Generator;

use LlmsTxt\Cache\TransientCache;
use LlmsTxt\Content\ContentAggregator;
use LlmsTxt\Core\Plugin;

/**
 * Generates the complete llms.txt file.
 *
 * Assembles header, description, and content sections into a valid llms.txt file.
 *
 * @package LlmsTxt\Generator
 */
final class LlmsTxtGenerator
{
    /**
     * @param ContentAggregator $aggregator Content aggregator.
     * @param TransientCache    $cache      Cache handler.
     */
    public function __construct(
        private readonly ContentAggregator $aggregator,
        private readonly TransientCache $cache
    ) {}

    /**
     * Generate the complete llms.txt content.
     *
     * @param bool $useCache Whether to use cached content if available.
     * @return string The complete llms.txt file content.
     */
    public function generate(bool $useCache = true): string
    {
        // Check cache first.
        if ($useCache) {
            $cached = $this->cache->get(full: true);
            if ($cached !== null) {
                return $cached;
            }
        }

        $settings = Plugin::getSettings();

        // Build the file.
        $parts = [];

        // H1 Header.
        $parts[] = $this->buildHeader($settings);

        // Blockquote description.
        $description = $this->buildDescription($settings);
        if ($description !== '') {
            $parts[] = '';
            $parts[] = $description;
        }

        // Additional info.
        $additionalInfo = $this->buildAdditionalInfo();
        if ($additionalInfo !== '') {
            $parts[] = '';
            $parts[] = $additionalInfo;
        }

        // Content sections.
        $collection = $this->aggregator->aggregate();
        if (!$collection->isEmpty()) {
            $parts[] = '';
            $parts[] = $collection->toMarkdown();
        }

        $content = implode("\n", $parts);

        /**
         * Filter the complete llms.txt content.
         *
         * @since 1.0.0
         *
         * @param string $content  The complete llms.txt content.
         * @param array  $settings Plugin settings.
         */
        $content = apply_filters('llms_txt_content', $content, $settings);

        // Cache the result.
        $this->cache->set($content, full: true);

        return $content;
    }

    /**
     * Build the H1 header.
     *
     * @param array $settings Plugin settings.
     * @return string H1 header line.
     */
    private function buildHeader(array $settings): string
    {
        $customHeader = $settings['custom_header'] ?? '';

        if (!empty($customHeader)) {
            $title = $customHeader;
        } else {
            $title = get_bloginfo('name');
        }

        /**
         * Filter the llms.txt header (H1).
         *
         * @since 1.0.0
         *
         * @param string $title    The header title.
         * @param array  $settings Plugin settings.
         */
        $title = apply_filters('llms_txt_header', $title, $settings);

        return "# {$title}";
    }

    /**
     * Build the blockquote description.
     *
     * @param array $settings Plugin settings.
     * @return string Blockquote description or empty string.
     */
    private function buildDescription(array $settings): string
    {
        $customDescription = $settings['custom_description'] ?? '';

        if (!empty($customDescription)) {
            $description = $customDescription;
        } else {
            $description = get_bloginfo('description');
        }

        if (empty($description)) {
            return '';
        }

        /**
         * Filter the llms.txt description (blockquote).
         *
         * @since 1.0.0
         *
         * @param string $description The description text.
         * @param array  $settings    Plugin settings.
         */
        $description = apply_filters('llms_txt_description', $description, $settings);

        // Format as blockquote.
        $lines = explode("\n", $description);
        $quotedLines = array_map(
            static fn(string $line): string => '> ' . trim($line),
            $lines
        );

        return implode("\n", $quotedLines);
    }

    /**
     * Build additional info section.
     *
     * @return string Additional info or empty string.
     */
    private function buildAdditionalInfo(): string
    {
        $info = [];

        // Add site URL.
        $siteUrl = home_url('/');
        $info[] = "Website: {$siteUrl}";

        /**
         * Filter additional info for llms.txt.
         *
         * @since 1.0.0
         *
         * @param array $info Array of info lines.
         */
        $info = apply_filters('llms_txt_additional_info', $info);

        if (empty($info)) {
            return '';
        }

        return implode("\n", $info);
    }

    /**
     * Get the content aggregator.
     *
     * @return ContentAggregator
     */
    public function getAggregator(): ContentAggregator
    {
        return $this->aggregator;
    }

    /**
     * Get the cache handler.
     *
     * @return TransientCache
     */
    public function getCache(): TransientCache
    {
        return $this->cache;
    }

    /**
     * Force regeneration of llms.txt.
     *
     * @return string Freshly generated content.
     */
    public function regenerate(): string
    {
        $this->cache->invalidate();
        return $this->generate(useCache: false);
    }
}
