<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

/**
 * Page builder integration for content extraction.
 *
 * Status of each builder:
 *
 * Needs special handling (post_content is empty):
 *   - Elementor    → JSON in _elementor_data
 *   - Bricks       → JSON in _bricks_page_content_2
 *   - Oxygen       → shortcodes+HTML in ct_builder_shortcodes
 *   - Thrive       → HTML in tve_updated_post
 *   - SiteOrigin   → serialized panels in panels_data (if plugin active, uses API)
 *
 * Already handled by the standard processContent() path:
 *   - Beaver Builder  → writes rendered HTML to post_content on publish
 *   - Divi Builder    → shortcodes in post_content, processed by do_shortcode()
 *   - WPBakery        → shortcodes in post_content, processed by do_shortcode()
 *   - Flatsome        → custom markup in post_content
 *   - Kadence Blocks  → Gutenberg blocks in post_content, processed by do_blocks()
 *
 * @package LlmsTxt\Content
 */
final class PageBuilderIntegration
{
    // -------------------------------------------------------------------------
    // Unified entry point
    // -------------------------------------------------------------------------

    /**
     * Try to extract plain text from any known page builder.
     *
     * Returns null when the post uses no supported builder whose content is
     * stored outside of post_content, so the caller should fall through to
     * the standard processContent() path.
     */
    public static function extractPageBuilderText(\WP_Post $post): ?string
    {
        if (self::isElementorPost($post)) {
            return self::extractElementorText($post);
        }

        if (self::isBricksPost($post)) {
            return self::extractBricksText($post);
        }

        if (self::isOxygenPost($post)) {
            return self::extractOxygenText($post);
        }

        if (self::isThrivePost($post)) {
            return self::extractThriveText($post);
        }

        if (self::isSiteOriginPost($post)) {
            return self::extractSiteOriginText($post);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Elementor
    // -------------------------------------------------------------------------

    public static function isElementorPost(\WP_Post $post): bool
    {
        return defined('ELEMENTOR_VERSION')
            && get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder';
    }

    /**
     * Extract plain text from Elementor's JSON widget tree.
     */
    public static function extractElementorText(\WP_Post $post): ?string
    {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (empty($raw) || !is_string($raw)) {
            return null;
        }

        $elements = json_decode($raw, true);
        if (!is_array($elements)) {
            return null;
        }

        $text = self::collectElementorText($elements);
        return self::normalise($text);
    }

    private static function collectElementorText(array $elements): string
    {
        $parts = [];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $settings   = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $widgetType = (string) ($element['widgetType'] ?? '');

            if (($element['elType'] ?? '') === 'widget') {
                switch ($widgetType) {
                    case 'text-editor':
                        if (!empty($settings['editor'])) {
                            $parts[] = wp_strip_all_tags((string) $settings['editor']);
                        }
                        break;

                    case 'heading':
                        if (!empty($settings['title'])) {
                            $parts[] = wp_strip_all_tags((string) $settings['title']);
                        }
                        break;

                    case 'image-box':
                    case 'icon-box':
                        foreach (['title_text', 'description_text'] as $key) {
                            if (!empty($settings[$key])) {
                                $parts[] = wp_strip_all_tags((string) $settings[$key]);
                            }
                        }
                        break;

                    case 'accordion':
                    case 'toggle':
                        foreach ($settings['tabs'] ?? [] as $tab) {
                            if (!empty($tab['tab_title'])) {
                                $parts[] = wp_strip_all_tags((string) $tab['tab_title']);
                            }
                            if (!empty($tab['tab_content'])) {
                                $parts[] = wp_strip_all_tags((string) $tab['tab_content']);
                            }
                        }
                        break;

                    default:
                        // Generic fallback: pick string values longer than 15 chars.
                        foreach ($settings as $key => $value) {
                            if (
                                is_string($value)
                                && !str_starts_with((string) $key, '_')
                                && mb_strlen(wp_strip_all_tags($value)) > 15
                            ) {
                                $parts[] = wp_strip_all_tags($value);
                            }
                        }
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $nested = self::collectElementorText($element['elements']);
                if (!empty($nested)) {
                    $parts[] = $nested;
                }
            }
        }

        return implode(' ', array_filter($parts));
    }

    // -------------------------------------------------------------------------
    // Bricks Builder
    // -------------------------------------------------------------------------

    public static function isBricksPost(\WP_Post $post): bool
    {
        return defined('BRICKS_VERSION')
            && !empty(get_post_meta($post->ID, '_bricks_page_content_2', true));
    }

    /**
     * Extract plain text from Bricks Builder's JSON element tree.
     *
     * Meta key: _bricks_page_content_2
     * Structure: flat array of elements, each with type/settings/children.
     */
    public static function extractBricksText(\WP_Post $post): ?string
    {
        $raw = get_post_meta($post->ID, '_bricks_page_content_2', true);
        if (empty($raw)) {
            return null;
        }

        $elements = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($elements)) {
            return null;
        }

        return self::normalise(self::collectBricksText($elements));
    }

    private static function collectBricksText(array $elements): string
    {
        $parts = [];
        $textFields = ['text', 'content', 'heading', 'caption', 'description', 'label', 'title'];

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

            foreach ($textFields as $field) {
                if (!empty($settings[$field]) && is_string($settings[$field])) {
                    $clean = wp_strip_all_tags($settings[$field]);
                    if (mb_strlen($clean) > 5) {
                        $parts[] = $clean;
                    }
                }
            }

            // Bricks nests children inside each element.
            if (!empty($element['children']) && is_array($element['children'])) {
                $nested = self::collectBricksText($element['children']);
                if (!empty($nested)) {
                    $parts[] = $nested;
                }
            }
        }

        return implode(' ', array_filter($parts));
    }

    // -------------------------------------------------------------------------
    // Oxygen Builder
    // -------------------------------------------------------------------------

    public static function isOxygenPost(\WP_Post $post): bool
    {
        // Oxygen stores its layout in ct_builder_shortcodes (all versions).
        return !empty(get_post_meta($post->ID, 'ct_builder_shortcodes', true));
    }

    /**
     * Extract plain text from Oxygen Builder's shortcode-wrapped HTML.
     *
     * Meta key: ct_builder_shortcodes
     * Format: Oxygen shortcodes wrapping raw HTML content, e.g.
     *   [ct_section id="1"][ct_div][ct_text]<p>Hello</p>[/ct_text][/ct_div][/ct_section]
     */
    public static function extractOxygenText(\WP_Post $post): ?string
    {
        $shortcodes = get_post_meta($post->ID, 'ct_builder_shortcodes', true);
        if (empty($shortcodes) || !is_string($shortcodes)) {
            return null;
        }

        // Strip [shortcode] tags while keeping the HTML content inside them.
        $html = preg_replace('/\[\/?\w[\w-]*(?:\s[^\]]*?)?\]/', '', $shortcodes) ?? $shortcodes;
        $text = wp_strip_all_tags($html);

        return self::normalise($text);
    }

    // -------------------------------------------------------------------------
    // Thrive Architect
    // -------------------------------------------------------------------------

    public static function isThrivePost(\WP_Post $post): bool
    {
        // Thrive stores processed HTML in tve_updated_post.
        return !empty(get_post_meta($post->ID, 'tve_updated_post', true));
    }

    /**
     * Extract plain text from Thrive Architect.
     *
     * Meta key: tve_updated_post  — contains the processed HTML content.
     */
    public static function extractThriveText(\WP_Post $post): ?string
    {
        $html = get_post_meta($post->ID, 'tve_updated_post', true);
        if (empty($html) || !is_string($html)) {
            return null;
        }

        return self::normalise(wp_strip_all_tags($html));
    }

    // -------------------------------------------------------------------------
    // SiteOrigin Page Builder
    // -------------------------------------------------------------------------

    public static function isSiteOriginPost(\WP_Post $post): bool
    {
        return !empty(get_post_meta($post->ID, 'panels_data', true));
    }

    /**
     * Extract plain text from SiteOrigin Page Builder.
     *
     * Meta key: panels_data — serialized array of rows/cells/widgets.
     * If the SiteOrigin plugin is active we use its render API.
     * Otherwise we fall back to a recursive string sweep of the widget data.
     */
    public static function extractSiteOriginText(\WP_Post $post): ?string
    {
        // If SiteOrigin is active, use its official render function.
        if (function_exists('siteorigin_panels_render')) {
            $html = siteorigin_panels_render($post->ID);
            if (!empty($html)) {
                return self::normalise(wp_strip_all_tags($html));
            }
        }

        // Fallback: sweep the serialized panels_data for string values.
        $panelsData = get_post_meta($post->ID, 'panels_data', true);
        if (!is_array($panelsData)) {
            return null;
        }

        return self::normalise(self::sweepStrings($panelsData));
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively collect all string values from an array, skipping
     * internal/numeric-only values and keys that look like class names or IDs.
     *
     * @param array<mixed> $data
     */
    private static function sweepStrings(array $data, int $depth = 0): string
    {
        if ($depth > 8) {
            return '';
        }

        $parts = [];
        $skipKeys = ['class', 'style', 'id', 'type', 'label', 'panels_info'];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $skipKeys, true)) {
                continue;
            }

            if (is_string($value)) {
                $clean = wp_strip_all_tags($value);
                if (mb_strlen($clean) > 10 && !self::looksLikeCode($clean)) {
                    $parts[] = $clean;
                }
            } elseif (is_array($value)) {
                $nested = self::sweepStrings($value, $depth + 1);
                if (!empty($nested)) {
                    $parts[] = $nested;
                }
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Heuristic: skip values that are CSS, PHP class names, or base64.
     */
    private static function looksLikeCode(string $value): bool
    {
        // Skip if it looks like a PHP class name (no spaces, has backslash or double-colon).
        if (str_contains($value, '\\') || str_contains($value, '::')) {
            return true;
        }
        // Skip if it's suspiciously long without spaces (likely base64 or serialized data).
        if (mb_strlen($value) > 100 && !str_contains($value, ' ')) {
            return true;
        }
        return false;
    }

    /**
     * Normalise extracted text: collapse whitespace and return null if empty.
     */
    private static function normalise(string $text): ?string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        return !empty($text) ? $text : null;
    }
}
