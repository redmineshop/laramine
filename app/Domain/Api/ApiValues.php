<?php

namespace App\Domain\Api;

use App\Domain\CustomFields\CustomValueService;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Shared REST scalars: timestamps, person names, and custom-field values.
 */
final class ApiValues
{
    public function __construct(
        private readonly CustomValueService $customValues,
    ) {}

    public function stamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(DateTimeImmutable::createFromInterface($value))
                ->utc()
                ->format('Y-m-d\TH:i:s\Z');
        }
        if (is_string($value)) {
            return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return null;
    }

    public function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        return null;
    }

    /**
     * @return array{id: int, name: string}
     */
    public function ref(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name];
    }

    public function personName(User $user): string
    {
        if ($user->type === User::TYPE_GROUP) {
            return (string) $user->lastname;
        }

        return trim((string) $user->firstname.' '.(string) $user->lastname);
    }

    public function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array{id: int, name: string, value: string|list<string>}>
     */
    public function customFields(?User $actor, Model $record): array
    {
        $rows = [];
        foreach ($this->customValues->read($actor, $record) as $field) {
            $multiple = is_array($field->value);
            $rows[] = [
                'id' => $field->id,
                'name' => $field->name,
                'value' => $multiple ? $this->stringList($field->value) : $this->scalarString($field->value),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            $strings[] = $this->scalarString($value);
        }

        return $strings;
    }

    private function scalarString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            $formatted = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }
        if (is_string($value)) {
            return $value;
        }

        return '';
    }
}
