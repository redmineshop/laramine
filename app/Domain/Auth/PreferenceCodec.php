<?php

namespace App\Domain\Auth;

use JsonException;

/**
 * JSON codec for `user_preferences.others`.
 *
 * Redmine stores a Ruby YAML hash. This column is encoded as a JSON object.
 * Text that is not a JSON object is ignored on read.
 */
final class PreferenceCodec
{
    /**
     * @var array<string, list<string>>
     */
    private const CHOICES = [
        'comments_sorting' => ['asc', 'desc'],
        'textarea_font' => ['monospace', 'proportional'],
        'history_default_tab' => ['notes', 'history', 'properties', 'time_entries', 'changesets'],
    ];

    /**
     * @var list<string>
     */
    private const FLAGS = [
        'warn_on_leaving_unsaved',
        'no_self_notified',
    ];

    /**
     * @return array<string, bool|string>
     */
    public function decode(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        $clean = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $normalized = $this->normalize($key, $value);
            if ($normalized !== null) {
                $clean[$key] = $normalized;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function encode(array $input): string
    {
        $clean = [];
        foreach ($input as $key => $value) {
            if (! array_key_exists($key, self::CHOICES) && ! in_array($key, self::FLAGS, true) && ! $this->ganttKey($key)) {
                throw new AccountValidationException([
                    'others' => ['Preference key is unknown: '.$key.'.'],
                ]);
            }
            $normalized = $this->normalize($key, $value);
            if ($normalized === null) {
                throw new AccountValidationException([
                    'others' => ['Preference value is invalid: '.$key.'.'],
                ]);
            }
            $clean[$key] = $normalized;
        }

        if ($clean === []) {
            return '';
        }

        try {
            return json_encode($clean, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new AccountValidationException([
                'others' => ['Preferences could not be stored.'],
            ]);
        }
    }

    private function normalize(string $key, mixed $value): bool|string|null
    {
        if ($key === 'gantt_zoom') {
            $text = is_int($value) ? (string) $value : $value;

            return is_string($text) && in_array($text, ['1', '2', '3', '4'], true) ? $text : null;
        }

        if ($key === 'gantt_months') {
            $text = is_int($value) ? (string) $value : $value;

            return is_string($text) && preg_match('/^[1-9]\d*$/', $text) === 1 ? $text : null;
        }

        if (in_array($key, self::FLAGS, true)) {
            if (is_bool($value)) {
                return $value;
            }
            if ($value === 1 || $value === '1' || $value === 'true') {
                return true;
            }
            if ($value === 0 || $value === '0' || $value === 'false') {
                return false;
            }

            return null;
        }

        if (! is_string($value) || ! in_array($value, self::CHOICES[$key] ?? [], true)) {
            return null;
        }

        return $value;
    }

    private function ganttKey(string $key): bool
    {
        return $key === 'gantt_zoom' || $key === 'gantt_months';
    }
}
