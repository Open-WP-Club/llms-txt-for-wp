# LLMs.txt Generator

Generate llms.txt and Markdown versions of your WordPress content for AI/LLM consumption.

## Description

This plugin implements the [llms.txt specification](https://llmstxt.org/) for WordPress, making your content easily accessible to AI language models and crawlers.

### Features

- **llms.txt Generation**: Creates a standardized llms.txt file at `/llms.txt`
- **Markdown Output**: Access any post in Markdown via `.md` URL extension
- **Content Negotiation**: Supports `Accept: text/markdown` HTTP header
- **ACF Integration**: Includes Advanced Custom Fields in Markdown output
- **SEO Meta Support**: Uses meta descriptions from Yoast, Rank Math, or All in One SEO
- **Custom Taxonomies**: Full support for custom taxonomies including ACF-registered ones
- **Caching**: Transient-based caching with automatic invalidation
- **Link Descriptions**: Optional short descriptions next to links

## Requirements

- WordPress 6.4 or higher
- PHP 8.2 or higher
- Composer (for installation)

## Installation

1. Upload the plugin to `/wp-content/plugins/llms-txt-generator`
2. Run `composer install --no-dev` in the plugin directory
3. Activate the plugin through the 'Plugins' menu
4. Configure settings under Settings > LLMs.txt

## Usage

### llms.txt File

Access your llms.txt file at:
```
https://yoursite.com/llms.txt
```

### Markdown Versions

Access any post or page in Markdown by adding `.md` to the URL:
```
https://yoursite.com/sample-page.md
```

Or send an HTTP request with the Accept header:
```
Accept: text/markdown
```

### Settings

Navigate to **Settings > LLMs.txt** to configure:

- **Post Types**: Choose which post types to include
- **Taxonomies**: Select taxonomies to list as sections
- **Posts Per Type**: Limit the number of posts per type
- **Link Descriptions**: Toggle short descriptions next to links
- **ACF Fields**: Include ACF data in Markdown output
- **SEO Meta**: Use SEO plugin meta descriptions
- **Custom Header**: Override the site title
- **Custom Description**: Override the site tagline
- **Cache Duration**: Set how long to cache the generated content

## Hooks & Filters

### Filters

```php
// Modify the complete llms.txt content
add_filter('llms_txt_content', function(string $content, array $settings): string {
    return $content;
}, 10, 2);

// Modify the header (H1)
add_filter('llms_txt_header', function(string $title, array $settings): string {
    return $title;
}, 10, 2);

// Modify the description (blockquote)
add_filter('llms_txt_description', function(string $description, array $settings): string {
    return $description;
}, 10, 2);

// Add additional info lines
add_filter('llms_txt_additional_info', function(array $info): array {
    $info[] = 'Contact: hello@example.com';
    return $info;
});

// Modify content collection
add_filter('llms_txt_content_collection', function(ContentCollection $collection, array $settings): ContentCollection {
    return $collection;
}, 10, 2);

// Modify markdown output for a post
add_filter('llms_txt_post_markdown', function(string $markdown, WP_Post $post): string {
    return $markdown;
}, 10, 2);

// Modify response headers
add_filter('llms_txt_response_headers', function(array $headers): array {
    $headers['X-Custom-Header'] = 'value';
    return $headers;
});
```

### Actions

```php
// Before llms.txt is served
add_action('llms_txt_before_serve', function(): void {
    // Your code here
});

// After llms.txt is served
add_action('llms_txt_after_serve', function(string $content): void {
    // Your code here
});

// After cache is invalidated
add_action('llms_txt_cache_invalidated', function(): void {
    // Your code here
});
```

## llms.txt Format

The generated file follows the llms.txt specification:

```markdown
# Site Name

> Site description goes here

Website: https://yoursite.com

## Posts

- [Post Title](https://yoursite.com/post-slug.md): Post description

## Pages

- [Page Title](https://yoursite.com/page-slug.md): Page description

## Categories

- [Category Name](https://yoursite.com/category/slug.md): Category description
```

## Development

### Structure

```
llms-txt-generator/
├── assets/
│   ├── css/admin.css
│   └── js/admin.js
├── languages/
├── src/
│   ├── Admin/SettingsPage.php
│   ├── Cache/TransientCache.php
│   ├── Content/
│   │   ├── AcfIntegration.php
│   │   ├── ContentAggregator.php
│   │   ├── ContentCollection.php
│   │   └── ContentItem.php
│   ├── Core/Plugin.php
│   ├── Generator/
│   │   ├── LlmsTxtGenerator.php
│   │   └── MarkdownConverter.php
│   └── Output/
│       ├── LlmsTxtEndpoint.php
│       └── MarkdownEndpoint.php
├── templates/
├── vendor/
├── composer.json
├── llms-txt-generator.php
├── README.md
└── uninstall.php
```

### Requirements for Development

- PHP 8.2+
- Composer
- WordPress 6.4+

### Coding Standards

This plugin follows:
- PSR-4 autoloading
- PSR-12 coding style
- WordPress Coding Standards where applicable
- Strict types enabled

## Changelog

### 1.0.0
- Initial release
- llms.txt generation
- Markdown output for posts
- ACF integration
- SEO meta support
- Admin settings with Alpine.js

## License

GPL v2 or later
