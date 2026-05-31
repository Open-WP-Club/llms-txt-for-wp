<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

/**
 * Multilingual integration for WPML and Polylang.
 *
 * Provides language switching around WP_Query calls so that llms.txt
 * can serve content in a single configured language.
 *
 * @package LlmsTxt\Content
 */
final class MultilingualIntegration
{
    /** Language code saved before switching, used to restore afterwards. */
    private static ?string $previousLanguage = null;

    public static function isWpmlActive(): bool
    {
        return defined('ICL_SITEPRESS_VERSION');
    }

    public static function isPolylangActive(): bool
    {
        return function_exists('pll_languages_list');
    }

    public static function isAnyActive(): bool
    {
        return self::isWpmlActive() || self::isPolylangActive();
    }

    /**
     * Get all available languages as [code => name].
     *
     * @return array<string, string>
     */
    public static function getLanguages(): array
    {
        if (self::isWpmlActive()) {
            $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
            if (is_array($langs)) {
                $result = [];
                foreach ($langs as $lang) {
                    if (isset($lang['language_code'], $lang['native_name'])) {
                        $result[(string) $lang['language_code']] = (string) $lang['native_name'];
                    }
                }
                return $result;
            }
        }

        if (self::isPolylangActive()) {
            $slugs = pll_languages_list();
            $names = pll_languages_list(['fields' => 'name']);
            if (is_array($slugs) && is_array($names) && count($slugs) === count($names)) {
                /** @var string[] $slugs */
                /** @var string[] $names */
                return array_combine(array_map('strval', $slugs), array_map('strval', $names));
            }
        }

        return [];
    }

    /**
     * Switch to the requested language before running queries.
     *
     * @param string $lang Language code, or empty to do nothing.
     */
    public static function switchLanguage(string $lang): void
    {
        if (empty($lang)) {
            return;
        }

        if (self::isWpmlActive()) {
            self::$previousLanguage = (string) apply_filters('wpml_current_language', null);
            do_action('wpml_switch_language', $lang);
        }
    }

    /**
     * Restore the language that was active before switchLanguage() was called.
     */
    public static function restoreLanguage(): void
    {
        if (self::isWpmlActive() && self::$previousLanguage !== null) {
            do_action('wpml_switch_language', self::$previousLanguage);
            self::$previousLanguage = null;
        }
    }

    /**
     * Add a 'lang' argument to WP_Query / get_terms args for Polylang.
     *
     * @param array<string, mixed> $args
     * @param string               $lang Language code, or empty string.
     * @return array<string, mixed>
     */
    public static function applyToQueryArgs(array $args, string $lang): array
    {
        if (empty($lang) || !self::isPolylangActive()) {
            return $args;
        }

        $args['lang'] = $lang;
        return $args;
    }
}
