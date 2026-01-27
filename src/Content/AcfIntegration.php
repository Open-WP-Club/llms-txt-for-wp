<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

/**
 * ACF (Advanced Custom Fields) integration.
 *
 * Handles extraction of ACF fields and custom taxonomies registered via ACF.
 *
 * @package LlmsTxt\Content
 */
final class AcfIntegration
{
    /**
     * Check if ACF is active.
     *
     * @return bool True if ACF is available.
     */
    public static function isActive(): bool
    {
        return function_exists('get_fields') && function_exists('acf_get_field_groups');
    }

    /**
     * Get all ACF taxonomies.
     *
     * ACF can register custom taxonomies via the ACF UI (since ACF 6.1).
     *
     * @return array<string, \WP_Taxonomy> Array of taxonomy objects keyed by name.
     */
    public function getAcfTaxonomies(): array
    {
        if (!self::isActive()) {
            return [];
        }

        // ACF 6.1+ registers taxonomies with 'acf' source.
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $acfTaxonomies = [];

        foreach ($taxonomies as $taxonomy) {
            // Check if registered by ACF (has _acf meta or specific naming).
            $isAcfTaxonomy = $this->isAcfRegisteredTaxonomy($taxonomy);

            if ($isAcfTaxonomy) {
                $acfTaxonomies[$taxonomy->name] = $taxonomy;
            }
        }

        return $acfTaxonomies;
    }

    /**
     * Check if a taxonomy was registered by ACF.
     *
     * @param \WP_Taxonomy $taxonomy The taxonomy to check.
     * @return bool True if registered by ACF.
     */
    private function isAcfRegisteredTaxonomy(\WP_Taxonomy $taxonomy): bool
    {
        // ACF 6.1+ marks taxonomies it creates.
        if (function_exists('acf_get_taxonomy_posts')) {
            $acfTaxonomies = acf_get_taxonomy_posts();
            return isset($acfTaxonomies[$taxonomy->name]);
        }

        // Fallback: check if there's an ACF internal post for this taxonomy.
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'acf-taxonomy'
                AND post_status = 'publish'
                AND post_name = %s
                LIMIT 1",
                $taxonomy->name
            )
        );

        return $result !== null;
    }

    /**
     * Get fields for a post.
     *
     * @param int|\WP_Post $post Post ID or object.
     * @return array<string, mixed> Array of field values keyed by field name.
     */
    public function getPostFields(int|\WP_Post $post): array
    {
        if (!self::isActive()) {
            return [];
        }

        $postId = $post instanceof \WP_Post ? $post->ID : $post;
        $fields = get_fields($postId);

        if (!is_array($fields)) {
            return [];
        }

        return $this->filterDisplayableFields($fields);
    }

    /**
     * Get fields for a term.
     *
     * @param int|\WP_Term $term Term ID or object.
     * @param string       $taxonomy Taxonomy name (required if using term ID).
     * @return array<string, mixed> Array of field values.
     */
    public function getTermFields(int|\WP_Term $term, string $taxonomy = ''): array
    {
        if (!self::isActive()) {
            return [];
        }

        if ($term instanceof \WP_Term) {
            $acfId = $term->taxonomy . '_' . $term->term_id;
        } else {
            $acfId = $taxonomy . '_' . $term;
        }

        $fields = get_fields($acfId);

        if (!is_array($fields)) {
            return [];
        }

        return $this->filterDisplayableFields($fields);
    }

    /**
     * Filter fields to only include displayable ones.
     *
     * Removes complex fields that can't be represented as text.
     *
     * @param array<string, mixed> $fields All fields.
     * @return array<string, mixed> Filtered fields.
     */
    private function filterDisplayableFields(array $fields): array
    {
        $filtered = [];

        foreach ($fields as $name => $value) {
            $formatted = $this->formatFieldValue($value);

            if ($formatted !== null) {
                $filtered[$name] = $formatted;
            }
        }

        return $filtered;
    }

    /**
     * Format a field value for display.
     *
     * @param mixed $value The field value.
     * @return string|null Formatted value or null if not displayable.
     */
    public function formatFieldValue(mixed $value): ?string
    {
        // String values.
        if (is_string($value)) {
            $clean = wp_strip_all_tags($value);
            return !empty($clean) ? $clean : null;
        }

        // Numeric values.
        if (is_numeric($value)) {
            return (string) $value;
        }

        // Boolean values.
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        // Arrays.
        if (is_array($value)) {
            return $this->formatArrayValue($value);
        }

        // WP_Post objects.
        if ($value instanceof \WP_Post) {
            return get_the_title($value);
        }

        // WP_Term objects.
        if ($value instanceof \WP_Term) {
            return $value->name;
        }

        // WP_User objects.
        if ($value instanceof \WP_User) {
            return $value->display_name;
        }

        return null;
    }

    /**
     * Format an array field value.
     *
     * @param array $value The array value.
     * @return string|null Formatted value.
     */
    private function formatArrayValue(array $value): ?string
    {
        // Empty array.
        if (empty($value)) {
            return null;
        }

        // Simple array of strings/numbers (like checkbox or select).
        if (isset($value[0]) && (is_string($value[0]) || is_numeric($value[0]))) {
            return implode(', ', array_map('strval', $value));
        }

        // Image/file field (has 'url' key).
        if (isset($value['url'])) {
            return $value['url'];
        }

        // Link field.
        if (isset($value['url'], $value['title'])) {
            return "[{$value['title']}]({$value['url']})";
        }

        // Post object field (single).
        if (isset($value['ID'])) {
            return get_the_title($value['ID']);
        }

        // Array of post objects.
        if (isset($value[0]['ID'])) {
            $titles = array_map(
                static fn($item) => get_the_title($item['ID']),
                $value
            );
            return implode(', ', $titles);
        }

        // Array of term objects.
        if (isset($value[0]['term_id'])) {
            $names = array_map(
                static fn($item) => $item['name'] ?? '',
                $value
            );
            return implode(', ', array_filter($names));
        }

        return null;
    }

    /**
     * Get all field groups for a post type.
     *
     * @param string $postType Post type slug.
     * @return array Array of field groups.
     */
    public function getFieldGroupsForPostType(string $postType): array
    {
        if (!self::isActive() || !function_exists('acf_get_field_groups')) {
            return [];
        }

        return acf_get_field_groups(['post_type' => $postType]);
    }

    /**
     * Build markdown section for ACF fields.
     *
     * @param array<string, string> $fields Formatted field values.
     * @return string Markdown content.
     */
    public function buildMarkdownSection(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $lines = [];

        foreach ($fields as $name => $value) {
            // Convert field name to readable label.
            $label = ucwords(str_replace(['_', '-'], ' ', $name));
            $lines[] = "**{$label}:** {$value}";
        }

        return implode("\n\n", $lines);
    }
}
