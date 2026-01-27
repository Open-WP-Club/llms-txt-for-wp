<?php

declare(strict_types=1);

namespace LlmsTxt\Content;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Collection of content sections for llms.txt.
 *
 * Implements Countable and IteratorAggregate for easy iteration.
 *
 * @package LlmsTxt\Content
 * @implements IteratorAggregate<string, ContentItem[]>
 */
final class ContentCollection implements Countable, IteratorAggregate
{
    /**
     * @var array<string, ContentItem[]> Sections with their items.
     */
    private array $sections = [];

    /**
     * @var ContentItem[] Optional items (can be skipped for shorter context).
     */
    private array $optionalItems = [];

    /**
     * Add a section to the collection.
     *
     * @param string        $name  Section name (used as H2 heading).
     * @param ContentItem[] $items Items in this section.
     */
    public function addSection(string $name, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $this->sections[$name] = $items;
    }

    /**
     * Get a section by name.
     *
     * @param string $name Section name.
     * @return ContentItem[]|null Items or null if section doesn't exist.
     */
    public function getSection(string $name): ?array
    {
        return $this->sections[$name] ?? null;
    }

    /**
     * Check if a section exists.
     *
     * @param string $name Section name.
     * @return bool True if section exists.
     */
    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Remove a section.
     *
     * @param string $name Section name.
     */
    public function removeSection(string $name): void
    {
        unset($this->sections[$name]);
    }

    /**
     * Get all sections.
     *
     * @return array<string, ContentItem[]>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * Add optional items (for the "Optional" section).
     *
     * @param ContentItem[] $items Optional items.
     */
    public function addOptionalItems(array $items): void
    {
        $this->optionalItems = array_merge($this->optionalItems, $items);
    }

    /**
     * Get optional items.
     *
     * @return ContentItem[]
     */
    public function getOptionalItems(): array
    {
        return $this->optionalItems;
    }

    /**
     * Count total items across all sections.
     *
     * @return int Total item count.
     */
    public function count(): int
    {
        $count = count($this->optionalItems);

        foreach ($this->sections as $items) {
            $count += count($items);
        }

        return $count;
    }

    /**
     * Count sections.
     *
     * @return int Section count.
     */
    public function countSections(): int
    {
        $count = count($this->sections);

        if (!empty($this->optionalItems)) {
            $count++;
        }

        return $count;
    }

    /**
     * Check if collection is empty.
     *
     * @return bool True if no items in any section.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Get iterator for sections.
     *
     * @return Traversable<string, ContentItem[]>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->sections);
    }

    /**
     * Convert collection to llms.txt format.
     *
     * @return string Formatted content for llms.txt.
     */
    public function toMarkdown(): string
    {
        $lines = [];

        foreach ($this->sections as $name => $items) {
            $lines[] = "## {$name}";
            $lines[] = '';

            foreach ($items as $item) {
                $lines[] = $item->toLine();
            }

            $lines[] = '';
        }

        // Add optional section if there are optional items.
        if (!empty($this->optionalItems)) {
            $lines[] = '## Optional';
            $lines[] = '';

            foreach ($this->optionalItems as $item) {
                $lines[] = $item->toLine();
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
