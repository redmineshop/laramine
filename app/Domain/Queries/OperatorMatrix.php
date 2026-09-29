<?php

namespace App\Domain\Queries;

/**
 * Issue filter operators from the Redmine 7.0.1 catalog (names and semantics only).
 *
 * Every operator in that catalog is compiled. Integer, float, and hour filters
 * do not include `!`; the catalog uses `!*` for a blank number.
 */
final class OperatorMatrix
{
    /**
     * @var list<string>
     */
    public const SHIPPED = [
        '=', '!', 'o', 'c', '!*', '*', '>=', '<=', '><',
        't', 'ld', 'w', 'lw', 'm', 'lm', 'y', '~', '!~',
        '<t+', '>t+', '><t+', 't+', 'nd', 'nw', 'nm',
        '>t-', '<t-', '><t-', 't-', 'l2w',
        '*~', '^', '$',
        '=p', '=!p', '!p', '*o', '!o',
        'ev', '!ev', 'cf',
    ];

    /**
     * No catalog operator is left uncompiled. Field-level gaps live in DeferredIssueFilters.
     *
     * @var list<string>
     */
    public const DEFERRED = [];

    /**
     * Full operator list for each filter type.
     *
     * @var array<string, list<string>>
     */
    private const BY_TYPE = [
        'list' => ['=', '!'],
        'list_with_history' => ['=', '!', 'ev', '!ev', 'cf'],
        'list_status' => ['o', '=', '!', 'ev', '!ev', 'cf', 'c', '*'],
        'list_optional' => ['=', '!', '!*', '*'],
        'list_optional_with_history' => ['=', '!', 'ev', '!ev', 'cf', '!*', '*'],
        'list_subprojects' => ['*', '!*', '=', '!'],
        'date' => [
            '=', '>=', '<=', '><', '<t+', '>t+', '><t+', 't+', 'nd', 't', 'ld',
            'nw', 'w', 'lw', 'l2w', 'nm', 'm', 'lm', 'y', '>t-', '<t-', '><t-', 't-',
            '!*', '*',
        ],
        'date_past' => [
            '=', '>=', '<=', '><', '>t-', '<t-', '><t-', 't-', 't', 'ld',
            'w', 'lw', 'l2w', 'm', 'lm', 'y', '!*', '*',
        ],
        'string' => ['~', '*~', '=', '!~', '!', '^', '$', '!*', '*'],
        'text' => ['~', '*~', '!~', '^', '$', '!*', '*'],
        'search' => ['~', '*~', '!~'],
        'integer' => ['=', '>=', '<=', '><', '!*', '*'],
        'float' => ['=', '>=', '<=', '><', '!*', '*'],
        'hour' => ['=', '>=', '<=', '><', '!*', '*'],
        'relation' => ['=', '!', '=p', '=!p', '!p', '*o', '!o', '!*', '*'],
        'tree' => ['=', '~', '!*', '*'],
    ];

    /**
     * Text filters gain exact `=` so subject and description can match a whole value.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_SHIPPED = [
        'text' => ['='],
    ];

    public static function assert(string $filterType, string $operator, string $field): void
    {
        if (self::acceptance($filterType, $operator) === 'shipped') {
            return;
        }

        throw new QueryValidationException('Operator '.$operator.' is not valid for '.$field.'.');
    }

    public static function acceptance(string $filterType, string $operator): string
    {
        $full = self::BY_TYPE[$filterType] ?? null;
        if ($full === null) {
            return 'unknown_type';
        }

        $extras = self::EXTRA_SHIPPED[$filterType] ?? [];
        if (in_array($operator, $full, true) || in_array($operator, $extras, true)) {
            return 'shipped';
        }

        return 'invalid';
    }

    /**
     * @return list<string>
     */
    public static function shippedFor(string $filterType): array
    {
        $full = self::BY_TYPE[$filterType] ?? [];
        $shipped = [];
        foreach ([...$full, ...self::EXTRA_SHIPPED[$filterType] ?? []] as $operator) {
            if (self::acceptance($filterType, $operator) === 'shipped' && ! in_array($operator, $shipped, true)) {
                $shipped[] = $operator;
            }
        }

        return $shipped;
    }

    public static function valueMode(string $operator): string
    {
        return match ($operator) {
            'o', 'c', '*', '!*', 't', 'ld', 'w', 'lw', 'm', 'lm', 'y', 'nd', 'nw', 'nm', 'l2w', '*o', '!o' => 'none',
            '><' => 'two',
            '=', '!', '>=', '<=', '~', '!~', '*~', '^', '$',
            '<t+', '>t+', '><t+', 't+', '>t-', '<t-', '><t-', 't-',
            '=p', '=!p', '!p', 'ev', '!ev', 'cf' => 'one',
            default => 'unknown',
        };
    }
}
