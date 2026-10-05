<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;

/**
 * String rules (regexp, min length, max length) plus an optional URL template.
 *
 * `format_store.url_pattern` is expanded by `formattedUrl`. Substituted tokens
 * are percent-encoded. This format does not request the URL.
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
     * Pattern with tokens replaced. Null when no pattern is stored.
     *
     * `%value%`, `%id%`, `%project_id%`, `%project_identifier%`, and `%mN%`
     * (regexp capture N, with `%m0%` the whole match) are each percent-encoded.
     * Bytes outside the RFC 3986 unreserved and reserved sets become `%HH`, so
     * a space is `%20` while `:` and `/` stay. Text outside tokens is copied.
     * A missing id, project, or capture becomes an empty string.
     */
    public function formattedUrl(
        CustomField $field,
        string $value,
        ?int $recordId,
        ?int $projectId = null,
        ?string $projectIdentifier = null,
    ): ?string {
        $pattern = $this->urlPattern($field);
        if ($pattern === null) {
            return null;
        }

        $id = $recordId === null ? '' : (string) $recordId;
        $project = $projectId === null ? '' : (string) $projectId;
        $identifier = $projectIdentifier ?? '';
        $captures = $this->captures($field, $value);

        $built = preg_replace_callback(
            '/%(?:value|id|project_id|project_identifier|m(\d+))%/',
            function (array $match) use ($value, $id, $project, $identifier, $captures): string {
                $token = $match[0];
                if (preg_match('/^%m(\d+)%$/', $token, $groupMatch) === 1) {
                    return $this->encodeComponent($captures[(int) $groupMatch[1]] ?? '');
                }

                $replacement = match ($token) {
                    '%value%' => $value,
                    '%id%' => $id,
                    '%project_id%' => $project,
                    '%project_identifier%' => $identifier,
                    default => '',
                };

                return $this->encodeComponent($replacement);
            },
            $pattern,
        );

        return is_string($built) ? $built : $pattern;
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

    /**
     * @return array<int, string>
     */
    private function captures(CustomField $field, string $value): array
    {
        $pattern = $field->regexp;
        if (! is_string($pattern) || $pattern === '') {
            return [];
        }

        $regex = FieldValues::compilePattern($pattern);
        if ($regex === null) {
            return [];
        }

        $matched = preg_match($regex, $value, $groups);
        if ($matched !== 1) {
            return [];
        }

        $captures = [];
        foreach ($groups as $index => $group) {
            if (! is_int($index)) {
                continue;
            }
            $captures[$index] = $group;
        }

        return $captures;
    }

    /**
     * Percent-encode one token the way a URI component encoder leaves reserved
     * characters in place and encodes a space as `%20`.
     */
    private function encodeComponent(string $value): string
    {
        $encoded = preg_replace_callback(
            '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=]/',
            static function (array $match): string {
                return sprintf('%%%02X', ord($match[0]));
            },
            $value,
        );

        return is_string($encoded) ? $encoded : $value;
    }
}
