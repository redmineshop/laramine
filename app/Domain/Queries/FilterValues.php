<?php

namespace App\Domain\Queries;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Normalizes filter values after the operator has been accepted for the field.
 */
final class FilterValues
{
    public static function assertCount(QueryFilter $filter): void
    {
        $mode = OperatorMatrix::valueMode($filter->operator);
        $count = count(self::present($filter));

        if ($mode === 'none' || ($mode === 'one' && $count >= 1) || ($mode === 'two' && $count >= 2)) {
            return;
        }

        if ($mode === 'two') {
            throw new QueryValidationException('Filter '.$filter->field.' needs two values.');
        }

        if ($mode === 'one') {
            throw new QueryValidationException('Filter '.$filter->field.' needs a value.');
        }

        throw new QueryValidationException('Operator '.$filter->operator.' is not valid for '.$filter->field.'.');
    }

    /**
     * @return list<string>
     */
    public static function present(QueryFilter $filter): array
    {
        $values = [];
        foreach ($filter->values as $value) {
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<int>
     */
    public static function ids(QueryFilter $filter, ?User $actor = null, bool $allowMe = false, bool $expandGroups = false): array
    {
        $ids = [];
        foreach (self::present($filter) as $value) {
            if ($value === 'me') {
                $ids[] = self::currentUserId($filter, $actor, $allowMe);
                if ($expandGroups && $actor !== null) {
                    array_push($ids, ...self::groupIds($actor));
                }

                continue;
            }
            if (preg_match('/^[+]?\d+$/', $value) !== 1) {
                throw new QueryValidationException('Filter '.$filter->field.' needs an id.');
            }
            $ids[] = (int) $value;
        }

        return $ids;
    }

    /**
     * Non-negative day count for `t+` / `t-` operators.
     */
    public static function dayOffset(QueryFilter $filter): int
    {
        $values = self::present($filter);
        $value = $values[0] ?? '';
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new QueryValidationException('Filter '.$filter->field.' needs a non-negative day count.');
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public static function integers(QueryFilter $filter): array
    {
        return self::numbers($filter, '/^[+-]?\d+$/');
    }

    /**
     * Every integer token in the values. Redmine `=` scans `value.first` with `/[+-]?\d+/`.
     * An empty scan is a match-nothing list, not a validation error.
     *
     * @return list<string>
     */
    public static function integerList(QueryFilter $filter): array
    {
        $numbers = [];
        foreach (self::present($filter) as $value) {
            $matched = preg_match_all('/[+-]?\d+/', $value, $matches);
            if ($matched === false || $matches[0] === []) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $numbers[] = $match;
            }
        }

        return $numbers;
    }

    /**
     * Decimal ids embedded in a tree filter value. Redmine scans `/\d+/` and matches nothing when none remain.
     *
     * @return list<int>
     */
    public static function scannedIds(QueryFilter $filter): array
    {
        $ids = [];
        foreach (self::present($filter) as $value) {
            $matched = preg_match_all('/\d+/', $value, $matches);
            if ($matched === false) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $ids[] = (int) $match;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    public static function decimals(QueryFilter $filter): array
    {
        return self::numbers($filter, '/^[+-]?\d+(?:\.\d+)?$/');
    }

    /**
     * @return list<string>
     */
    public static function dates(QueryFilter $filter): array
    {
        $dates = [];
        foreach (self::present($filter) as $value) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
                throw new QueryValidationException('Filter '.$filter->field.' needs a YYYY-MM-DD date.');
            }
            $dates[] = $value;
        }

        return $dates;
    }

    /**
     * @return list<int>
     */
    public static function flags(QueryFilter $filter): array
    {
        $flags = [];
        foreach (self::present($filter) as $value) {
            $flags[] = match (strtolower($value)) {
                '1', 'true' => 1,
                '0', 'false' => 0,
                default => throw new QueryValidationException('Filter '.$filter->field.' needs 0 or 1.'),
            };
        }

        return $flags;
    }

    /**
     * Whitespace-separated tokens. Each stored value is split, then the tokens are AND-ed.
     *
     * @return list<string>
     */
    public static function tokens(QueryFilter $filter): array
    {
        $tokens = [];
        foreach (self::present($filter) as $value) {
            $parts = preg_split('/\s+/u', trim($value));
            if ($parts === false) {
                continue;
            }
            foreach ($parts as $part) {
                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }

        if ($tokens === []) {
            throw new QueryValidationException('Filter '.$filter->field.' needs a value.');
        }

        return $tokens;
    }

    public static function like(string $token): string
    {
        return '%'.self::escaped($token).'%';
    }

    public static function likePrefix(string $token): string
    {
        return self::escaped($token).'%';
    }

    public static function likeSuffix(string $token): string
    {
        return '%'.self::escaped($token);
    }

    private static function currentUserId(QueryFilter $filter, ?User $actor, bool $allowMe): int
    {
        if (! $allowMe) {
            throw new QueryValidationException('Filter value me is not valid for '.$filter->field.'.');
        }

        if ($actor === null || $actor->type !== User::TYPE_USER || ! $actor->isActive()) {
            throw new QueryValidationException('Filter value me needs an active user.');
        }

        return (int) $actor->id;
    }

    /**
     * Group principals the actor belongs to. Redmine adds these for `assigned_to_id` and `watcher_id` `me`.
     *
     * @return list<int>
     */
    private static function groupIds(User $actor): array
    {
        $ids = [];
        foreach (DB::table('groups_users')->where('user_id', $actor->id)->pluck('group_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private static function escaped(string $token): string
    {
        $lower = mb_strtolower($token, 'UTF-8');

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $lower);
    }

    /**
     * @return list<string>
     */
    private static function numbers(QueryFilter $filter, string $pattern): array
    {
        $numbers = [];
        foreach (self::present($filter) as $value) {
            if (preg_match($pattern, $value) !== 1) {
                throw new QueryValidationException('Filter '.$filter->field.' needs a number.');
            }
            $numbers[] = $value;
        }

        return $numbers;
    }
}
