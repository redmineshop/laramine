<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\FieldValues;
use App\Models\CustomField;

/**
 * String rules (regexp, min length, max length) plus an optional URL template.
 *
 * `format_store.url_pattern` is expanded by `formattedUrl`. Substituted tokens
 * are percent-encoded. `outboundUrl` is the URL a client fetches. A stored
 * value with no pattern and no `scheme://` prefix is returned with `http://`
 * in front. This format does not request the URL.
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

    /**
     * URL a client fetches for this stored value.
     *
     * A pattern result is returned as expanded. Otherwise a value that already
     * starts with `scheme://` is returned unchanged, and every other value is
     * prefixed with `http://`. The server does not request this URL.
     */
    public function outboundUrl(
        CustomField $field,
        string $value,
        ?int $recordId,
        ?int $projectId = null,
        ?string $projectIdentifier = null,
    ): string {
        $formatted = $this->formattedUrl($field, $value, $recordId, $projectId, $projectIdentifier);
        if (is_string($formatted) && $formatted !== '') {
            return $formatted;
        }
        if (preg_match('/\A[a-z][a-z0-9+.-]*:\/\//i', $value) === 1) {
            return $value;
        }

        return 'http://'.$value;
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
        if (! $this->isSafeUrlPattern($raw)) {
            return 'url_pattern must use http, https, ftp, mailto, or a path.';
        }

        return null;
    }

    /**
     * True when the pattern, with substitution tokens removed, is empty, a
     * single-slash path, or an absolute URL whose scheme is http, https, ftp,
     * or mailto.
     */
    private function isSafeUrlPattern(string $pattern): bool
    {
        $remainder = preg_replace('/%(?:value|id|project_id|project_identifier|m\d+)%/', '', $pattern);
        if (! is_string($remainder) || preg_match('/[\x00-\x20\x7F]/', $remainder) === 1) {
            return false;
        }
        if ($remainder === '' || preg_match('#\A/(?!/)#', $remainder) === 1 || preg_match('/\A[?#]/', $remainder) === 1) {
            return true;
        }

        $scheme = parse_url($remainder, PHP_URL_SCHEME);
        if (! is_string($scheme)) {
            return false;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'ftp', 'mailto'], true);
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
