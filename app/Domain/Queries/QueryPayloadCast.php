<?php

namespace App\Domain\Queries;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads JSON or legacy YAML from a `queries` text column and writes JSON.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class QueryPayloadCast implements CastsAttributes
{
    /**
     * @param  'filters'|'column_names'|'sort_criteria'|'options'  $part
     */
    public function __construct(private readonly string $part) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return match ($this->part) {
            'filters' => QueryPayload::filters($value),
            'column_names' => QueryPayload::columnNames($value),
            'sort_criteria' => QueryPayload::sort($value),
            'options' => QueryPayload::options($value),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match ($this->part) {
            'filters' => QueryPayload::encodeFilters(QueryPayload::filters($value)),
            'column_names' => QueryPayload::encodeColumnNames(QueryPayload::columnNames($value)),
            'sort_criteria' => QueryPayload::encodeSort(QueryPayload::sort($value)),
            'options' => QueryPayload::encodeOptions(QueryPayload::options($value)),
        };
    }
}
