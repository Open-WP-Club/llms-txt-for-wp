<?php

declare(strict_types=1);

namespace LlmsTxt\Admin;

use LlmsTxt\Cache\TransientCache;
use LlmsTxt\Core\Plugin;

/**
 * Admin settings page for the plugin.
 *
 * Uses a custom template with modern UI design.
 *
 * @package LlmsTxt\Admin
 */
final class SettingsPage
{
    private const OPTION_GROUP = 'llms_txt_settings_group';
    private const OPTION_NAME = 'llms_txt_settings';
    private const PAGE_SLUG = 'llms-txt-settings';

    public function __construct(
        private readonly TransientCache $cache
    ) {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_llms_txt_clear_cache', [$this, 'handleClearCache']);
        add_filter('plugin_action_links_' . LLMS_TXT_BASENAME, [$this, 'addSettingsLink']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('LLMs.txt Generator', 'llms-txt-generator'),
            __('LLMs.txt', 'llms-txt-generator'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => $this->getDefaults(),
            ]
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_script(
            'llms-txt-admin',
            LLMS_TXT_URL . 'assets/js/admin.js',
            [],
            LLMS_TXT_VERSION,
            ['in_footer' => true]
        );

        wp_localize_script('llms-txt-admin', 'llmsTxtAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('llms_txt_admin'),
            'strings' => [
                'cacheCleared' => __('Cache cleared successfully!', 'llms-txt-generator'),
                'error' => __('An error occurred.', 'llms-txt-generator'),
                'clearing' => __('Clearing...', 'llms-txt-generator'),
                'clearCache' => __('Clear Cache', 'llms-txt-generator'),
            ],
        ]);

        wp_enqueue_style(
            'llms-txt-admin',
            LLMS_TXT_URL . 'assets/css/admin.css',
            [],
            LLMS_TXT_VERSION
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Plugin::getSettings();
        $previewUrl = home_url('/llms.txt');
        $postTypes = get_post_types(['public' => true], 'objects');
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $acfActive = function_exists('get_fields');

        $cacheDurations = [
            21600 => __('6 hours', 'llms-txt-generator'),
            86400 => __('1 day', 'llms-txt-generator'),
            604800 => __('7 days', 'llms-txt-generator'),
            2592000 => __('30 days', 'llms-txt-generator'),
        ];
        ?>
        <div class="wrap llms-txt-wrap">

            <!-- Header -->
            <div class="llms-txt-header">
                <h1 class="llms-txt-header-title">
                    <span class="dashicons dashicons-media-text"></span>
                    <?php esc_html_e('LLMs.txt Generator', 'llms-txt-generator'); ?>
                </h1>
                <p class="llms-txt-header-desc">
                    <?php esc_html_e('Generate a standardized llms.txt file and Markdown versions of your content for AI language models and crawlers.', 'llms-txt-generator'); ?>
                </p>
                <div class="llms-txt-actions">
                    <a href="<?php echo esc_url($previewUrl); ?>" target="_blank" class="button button-primary">
                        <?php esc_html_e('View llms.txt', 'llms-txt-generator'); ?>
                    </a>
                    <button type="button" class="button button-secondary" id="llms-clear-cache">
                        <?php esc_html_e('Clear Cache', 'llms-txt-generator'); ?>
                    </button>
                    <span id="llms-cache-message" class="llms-txt-message" style="display: none;"></span>
                </div>
            </div>

            <form action="options.php" method="post">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <!-- General Settings -->
                <div class="llms-txt-card">
                    <div class="llms-txt-card-header">
                        <h2><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('General Settings', 'llms-txt-generator'); ?></h2>
                    </div>
                    <div class="llms-txt-card-body">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable LLMs.txt', 'llms-txt-generator'); ?></th>
                                <td>
                                    <div class="llms-txt-toggle">
                                        <input type="checkbox"
                                               id="llms_enabled"
                                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[enabled]"
                                               value="1"
                                               <?php checked($settings['enabled'] ?? true); ?>>
                                        <label for="llms_enabled" class="llms-txt-toggle-label">
                                            <?php esc_html_e('Enable the llms.txt endpoint', 'llms-txt-generator'); ?>
                                        </label>
                                    </div>
                                    <p class="description">
                                        <?php
                                        printf(
                                            esc_html__('When enabled, your llms.txt file will be accessible at %s', 'llms-txt-generator'),
                                            '<code>' . esc_html($previewUrl) . '</code>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Cache Duration', 'llms-txt-generator'); ?></th>
                                <td>
                                    <select name="<?php echo esc_attr(self::OPTION_NAME); ?>[cache_duration]">
                                        <?php foreach ($cacheDurations as $seconds => $label): ?>
                                            <option value="<?php echo esc_attr((string) $seconds); ?>" <?php selected($settings['cache_duration'] ?? 86400, $seconds); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e('How long to cache the generated llms.txt file. Cache is automatically cleared when you update content.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Content Settings -->
                <div class="llms-txt-card">
                    <div class="llms-txt-card-header">
                        <h2><span class="dashicons dashicons-admin-page"></span> <?php esc_html_e('Content Settings', 'llms-txt-generator'); ?></h2>
                    </div>
                    <div class="llms-txt-card-body">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Post Types', 'llms-txt-generator'); ?></th>
                                <td>
                                    <div class="llms-txt-checkbox-group">
                                        <?php foreach ($postTypes as $postType):
                                            if ($postType->name === 'attachment') continue;
                                            $checked = in_array($postType->name, $settings['post_types'] ?? ['post', 'page'], true);
                                        ?>
                                            <label>
                                                <input type="checkbox"
                                                       name="<?php echo esc_attr(self::OPTION_NAME); ?>[post_types][]"
                                                       value="<?php echo esc_attr($postType->name); ?>"
                                                       <?php checked($checked); ?>>
                                                <?php echo esc_html($postType->labels->name); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Select which post types to include in your llms.txt file. Each post type will appear as a separate section.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Taxonomies', 'llms-txt-generator'); ?></th>
                                <td>
                                    <div class="llms-txt-checkbox-group">
                                        <?php foreach ($taxonomies as $taxonomy):
                                            $checked = in_array($taxonomy->name, $settings['taxonomies'] ?? ['category', 'post_tag'], true);
                                        ?>
                                            <label>
                                                <input type="checkbox"
                                                       name="<?php echo esc_attr(self::OPTION_NAME); ?>[taxonomies][]"
                                                       value="<?php echo esc_attr($taxonomy->name); ?>"
                                                       <?php checked($checked); ?>>
                                                <?php echo esc_html($taxonomy->labels->name); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Include taxonomy archives (categories, tags, etc.) in your llms.txt file.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Posts Per Type', 'llms-txt-generator'); ?></th>
                                <td>
                                    <input type="number"
                                           name="<?php echo esc_attr(self::OPTION_NAME); ?>[posts_per_type]"
                                           value="<?php echo esc_attr((string) ($settings['posts_per_type'] ?? 100)); ?>"
                                           min="1"
                                           max="1000"
                                           step="1">
                                    <p class="description">
                                        <?php esc_html_e('Maximum number of posts to include per post type. Higher values create larger files.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Link Descriptions', 'llms-txt-generator'); ?></th>
                                <td>
                                    <div class="llms-txt-toggle">
                                        <input type="checkbox"
                                               id="llms_link_descriptions"
                                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[link_descriptions]"
                                               value="1"
                                               <?php checked($settings['link_descriptions'] ?? true); ?>>
                                        <label for="llms_link_descriptions" class="llms-txt-toggle-label">
                                            <?php esc_html_e('Include short descriptions next to links', 'llms-txt-generator'); ?>
                                        </label>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Adds a brief description after each link to help AI models understand what each page is about.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('SEO Meta', 'llms-txt-generator'); ?></th>
                                <td>
                                    <div class="llms-txt-toggle">
                                        <input type="checkbox"
                                               id="llms_include_meta"
                                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[include_meta]"
                                               value="1"
                                               <?php checked($settings['include_meta'] ?? true); ?>>
                                        <label for="llms_include_meta" class="llms-txt-toggle-label">
                                            <?php esc_html_e('Use SEO meta descriptions when available', 'llms-txt-generator'); ?>
                                        </label>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e('Pulls descriptions from Yoast SEO, Rank Math, or All in One SEO if installed. Falls back to post excerpt.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('ACF Fields', 'llms-txt-generator'); ?></th>
                                <td>
                                    <div class="llms-txt-toggle <?php echo $acfActive ? '' : 'llms-txt-disabled'; ?>">
                                        <input type="checkbox"
                                               id="llms_include_acf"
                                               name="<?php echo esc_attr(self::OPTION_NAME); ?>[include_acf]"
                                               value="1"
                                               <?php checked($settings['include_acf'] ?? true); ?>
                                               <?php disabled(!$acfActive); ?>>
                                        <label for="llms_include_acf" class="llms-txt-toggle-label">
                                            <?php esc_html_e('Include ACF custom fields in Markdown output', 'llms-txt-generator'); ?>
                                        </label>
                                    </div>
                                    <p class="description">
                                        <?php if ($acfActive): ?>
                                            <?php esc_html_e('Advanced Custom Fields data will be included when viewing individual posts as Markdown.', 'llms-txt-generator'); ?>
                                        <?php else: ?>
                                            <?php esc_html_e('Advanced Custom Fields plugin is not active. Install and activate ACF to use this feature.', 'llms-txt-generator'); ?>
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Customization -->
                <div class="llms-txt-card">
                    <div class="llms-txt-card-header">
                        <h2><span class="dashicons dashicons-edit"></span> <?php esc_html_e('Customization', 'llms-txt-generator'); ?></h2>
                    </div>
                    <div class="llms-txt-card-body">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Custom Title', 'llms-txt-generator'); ?></th>
                                <td>
                                    <input type="text"
                                           name="<?php echo esc_attr(self::OPTION_NAME); ?>[custom_header]"
                                           value="<?php echo esc_attr($settings['custom_header'] ?? ''); ?>"
                                           class="regular-text"
                                           placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                                    <p class="description">
                                        <?php esc_html_e('The main heading (H1) at the top of your llms.txt file. Leave empty to use your site title.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Custom Description', 'llms-txt-generator'); ?></th>
                                <td>
                                    <textarea name="<?php echo esc_attr(self::OPTION_NAME); ?>[custom_description]"
                                              rows="4"
                                              class="large-text"
                                              placeholder="<?php echo esc_attr(get_bloginfo('description')); ?>"><?php echo esc_textarea($settings['custom_description'] ?? ''); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e('A brief description that appears as a blockquote below the title. Leave empty to use your site tagline.', 'llms-txt-generator'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <div class="llms-txt-submit">
                            <?php submit_button(__('Save Settings', 'llms-txt-generator'), 'primary', 'submit', false); ?>
                        </div>
                    </div>
                </div>

            </form>

        </div>
        <?php
    }

    public function sanitizeSettings(mixed $input): array
    {
        $sanitized = [];

        $sanitized['enabled'] = !empty($input['enabled']);

        $allPostTypes = get_post_types(['public' => true]);
        $sanitized['post_types'] = isset($input['post_types']) && is_array($input['post_types'])
            ? array_values(array_intersect($input['post_types'], $allPostTypes))
            : ['post', 'page'];

        $allTaxonomies = get_taxonomies(['public' => true]);
        $sanitized['taxonomies'] = isset($input['taxonomies']) && is_array($input['taxonomies'])
            ? array_values(array_intersect($input['taxonomies'], $allTaxonomies))
            : [];

        $sanitized['posts_per_type'] = isset($input['posts_per_type'])
            ? min(1000, max(1, (int) $input['posts_per_type']))
            : 100;

        $sanitized['link_descriptions'] = !empty($input['link_descriptions']);
        $sanitized['include_acf'] = !empty($input['include_acf']);
        $sanitized['include_meta'] = !empty($input['include_meta']);

        $sanitized['custom_header'] = isset($input['custom_header'])
            ? sanitize_text_field($input['custom_header'])
            : '';

        $sanitized['custom_description'] = isset($input['custom_description'])
            ? sanitize_textarea_field($input['custom_description'])
            : '';

        $validDurations = [21600, 86400, 604800, 2592000];
        $sanitized['cache_duration'] = isset($input['cache_duration']) && in_array((int) $input['cache_duration'], $validDurations, true)
            ? (int) $input['cache_duration']
            : 86400;

        $this->cache->invalidate();

        return $sanitized;
    }

    private function getDefaults(): array
    {
        return [
            'enabled' => true,
            'post_types' => ['post', 'page'],
            'taxonomies' => ['category', 'post_tag'],
            'posts_per_type' => 100,
            'include_acf' => true,
            'include_meta' => true,
            'custom_header' => '',
            'custom_description' => '',
            'link_descriptions' => true,
            'cache_duration' => 86400,
        ];
    }

    public function handleClearCache(): void
    {
        check_ajax_referer('llms_txt_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'llms-txt-generator')], 403);
        }

        $this->cache->invalidate();

        wp_send_json_success(['message' => __('Cache cleared successfully.', 'llms-txt-generator')]);
    }

    public function addSettingsLink(array $links): array
    {
        $settingsLink = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=' . self::PAGE_SLUG),
            __('Settings', 'llms-txt-generator')
        );

        array_unshift($links, $settingsLink);

        return $links;
    }
}
