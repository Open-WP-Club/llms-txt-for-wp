<?php

declare(strict_types=1);

namespace LlmsTxt\Output;

use LlmsTxt\Core\Plugin;
use LlmsTxt\Generator\LlmsTxtGenerator;

/**
 * Handles the /llms.txt endpoint.
 *
 * Registers rewrite rules and serves the llms.txt file.
 *
 * @package LlmsTxt\Output
 */
final class LlmsTxtEndpoint
{
    /**
     * @param LlmsTxtGenerator $generator The llms.txt generator.
     */
    public function __construct(
        private readonly LlmsTxtGenerator $generator
    ) {}

    /**
     * Register rewrite rules for llms.txt.
     */
    public function registerRewriteRules(): void
    {
        // Add rewrite rule for llms.txt.
        add_rewrite_rule(
            '^llms\.txt$',
            'index.php?llms_txt=1',
            'top'
        );

        // Flush rewrite rules if needed.
        if (get_option('llms_txt_flush_rewrite')) {
            flush_rewrite_rules();
            delete_option('llms_txt_flush_rewrite');
        }
    }

    /**
     * Handle llms.txt requests.
     */
    public function handleRequest(): void
    {
        // Check if this is a llms.txt request.
        if (!get_query_var('llms_txt')) {
            return;
        }

        $settings = Plugin::getSettings();

        // Check if plugin is enabled.
        if (!($settings['enabled'] ?? true)) {
            $this->send404();
            return;
        }

        /**
         * Fires before llms.txt is served.
         *
         * @since 1.0.0
         */
        do_action('llms_txt_before_serve');

        // Generate content.
        $content = $this->generator->generate();

        // Send response.
        $this->sendResponse($content);
    }

    /**
     * Send the llms.txt response.
     *
     * @param string $content The content to send.
     */
    private function sendResponse(string $content): void
    {
        // Set headers.
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('Cache-Control: public, max-age=3600');

        /**
         * Filter response headers for llms.txt.
         *
         * @since 1.0.0
         *
         * @param array $headers Array of headers.
         */
        $customHeaders = apply_filters('llms_txt_response_headers', []);

        foreach ($customHeaders as $name => $value) {
            header("{$name}: {$value}");
        }

        // Output content.
        echo $content;

        /**
         * Fires after llms.txt is served.
         *
         * @since 1.0.0
         *
         * @param string $content The content that was served.
         */
        do_action('llms_txt_after_serve', $content);

        exit;
    }

    /**
     * Send a 404 response.
     */
    private function send404(): void
    {
        status_header(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo 'llms.txt is not available.';
        exit;
    }
}
