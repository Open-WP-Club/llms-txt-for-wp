<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

/**
 * WooCommerce integration for LLMs.txt Generator.
 *
 * Extracts product data (price, stock, attributes, rating) for AI-friendly output.
 *
 * @package LlmsTxt\Content
 */
final class WooCommerceIntegration
{
    /**
     * Check if WooCommerce is active and loaded.
     */
    public static function isActive(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    /**
     * Get the WooCommerce short description to use as the llms.txt description for a product.
     *
     * @param \WP_Post $post The product post.
     * @return string|null Short description or null if not available.
     */
    public static function getProductDescription(\WP_Post $post): ?string
    {
        $product = wc_get_product($post->ID);
        if (!$product instanceof \WC_Product) {
            return null;
        }

        $shortDesc = $product->get_short_description();
        if (!empty($shortDesc)) {
            return wp_strip_all_tags($shortDesc);
        }

        return null;
    }

    /**
     * Build a Markdown section with WooCommerce product details.
     *
     * @param \WP_Post $post     The product post.
     * @param array    $settings Plugin settings.
     * @return string Markdown content, or empty string if nothing to show.
     */
    public static function buildProductMarkdownSection(\WP_Post $post, array $settings): string
    {
        $product = wc_get_product($post->ID);
        if (!$product instanceof \WC_Product) {
            return '';
        }

        $lines = [];

        // Price
        if ($settings['wc_include_price'] ?? true) {
            $price = self::formatPrice($product);
            if ($price !== null) {
                $lines[] = "**Price:** {$price}";
            }
        }

        // Stock / availability
        if ($settings['wc_include_stock'] ?? true) {
            $lines[] = '**Availability:** ' . self::formatStockStatus($product);
        }

        // SKU
        $sku = $product->get_sku();
        if (!empty($sku)) {
            $lines[] = "**SKU:** {$sku}";
        }

        // Rating
        if ($settings['wc_include_rating'] ?? true) {
            $reviewCount = $product->get_review_count();
            if ($reviewCount > 0) {
                $avg = number_format((float) $product->get_average_rating(), 1);
                $reviewLabel = _n('review', 'reviews', $reviewCount, 'llms-txt-generator');
                $lines[] = "**Rating:** {$avg}/5 ({$reviewCount} {$reviewLabel})";
            }
        }

        if (empty($lines)) {
            return '';
        }

        $section = "## Product Details\n\n" . implode("  \n", $lines);

        // Attributes
        if ($settings['wc_include_attributes'] ?? true) {
            $attrSection = self::buildAttributesSection($product);
            if (!empty($attrSection)) {
                $section .= "\n\n" . $attrSection;
            }
        }

        return $section;
    }

    /**
     * Format product price as plain text.
     *
     * Handles simple, variable, and on-sale products.
     */
    private static function formatPrice(\WC_Product $product): ?string
    {
        if ($product->get_price() === '') {
            return null;
        }

        // get_price_html() handles variable ranges and sale strikethrough.
        $html = $product->get_price_html();
        if (!empty($html)) {
            $plain = wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return preg_replace('/\s+/', ' ', trim($plain)) ?: null;
        }

        return wp_strip_all_tags(wc_price((float) $product->get_price()));
    }

    /**
     * Format stock status as a readable string.
     */
    private static function formatStockStatus(\WC_Product $product): string
    {
        if (!$product->is_in_stock()) {
            return __('Out of Stock', 'llms-txt-generator');
        }

        if ($product->managing_stock()) {
            $qty = $product->get_stock_quantity();
            /* translators: %d: number of items in stock */
            return sprintf(__('In Stock (%d available)', 'llms-txt-generator'), (int) $qty);
        }

        return __('In Stock', 'llms-txt-generator');
    }

    /**
     * Build the product attributes / variations section in Markdown.
     */
    private static function buildAttributesSection(\WC_Product $product): string
    {
        $attributes = $product->get_attributes();
        if (empty($attributes)) {
            return '';
        }

        $lines = [];

        foreach ($attributes as $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute) {
                continue;
            }

            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }
                $label  = wc_attribute_label($attribute->get_name());
                $values = implode(', ', array_map(static fn(\WP_Term $t) => $t->name, $terms));
            } else {
                $options = $attribute->get_options();
                if (empty($options)) {
                    continue;
                }
                $label  = $attribute->get_name();
                $values = implode(', ', $options);
            }

            $lines[] = "- **{$label}:** {$values}";
        }

        if (empty($lines)) {
            return '';
        }

        return "### Attributes\n\n" . implode("\n", $lines);
    }
}
