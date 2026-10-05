<?php

namespace App\Domain\CustomFields\Formats;

use App\Models\CustomField;

/**
 * String rules (regexp, min length, max length) plus an optional URL template.
 *
 * `format_store.url_pattern` is stored for a later display step. `%value%` and
 * `%id%` are substituted literally. This format does not request the URL.
 */
final class LinkFormat extends BoundedTextFormat
{
    public function key(): string
    {
        return 'link';
    }

    public function supportsSearchable(): bool
    {
        return false;
    }

    public function queryFilterType(): string
    {
        return 'string';
    }

    public function validateDefinition(CustomField $field): array
    {
        $patternError = $this->urlPatternError($field);
        if ($patternError !== null) {
            return [$patternError];
        }

        return parent::validateDefinition($field);
    }

    /**
     * Pattern with `%value%` and `%id%` replaced. Null when no pattern is stored.
     */
    public function formattedUrl(CustomField $field, string $value, ?int $recordId): ?string
    {
        $pattern = $this->urlPattern($field);
        if ($pattern === null) {
            return null;
        }

        $id = $recordId === null ? '' : (string) $recordId;

        return str_replace(['%value%', '%id%'], [$value, $id], $pattern);
    }

    private function urlPatternError(CustomField $field): ?string
    {
        $store = $field->formatStoreData();
        if (! is_array($store) || ! array_key_exists('url_pattern', $store)) {
            return null;
        }

        $raw = $store['url_pattern'];
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return 'url_pattern must be a string.';
        }

        return null;
    }

    private function urlPattern(CustomField $field): ?string
    {
        if ($this->urlPatternError($field) !== null) {
            return null;
        }

        $store = $field->formatStoreData();
        if (! is_array($store) || ! array_key_exists('url_pattern', $store)) {
            return null;
        }

        $raw = $store['url_pattern'];
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return $raw;
    }
}
