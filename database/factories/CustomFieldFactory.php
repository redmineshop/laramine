<?php

namespace Database\Factories;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Minimal issue string field. Tracker and project links are attached by the test.
 *
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => substr('cf'.bin2hex(random_bytes(8)), 0, 30),
            'field_format' => 'string',
            'type' => 'IssueCustomField',
            'editable' => true,
            'visible' => true,
            'is_required' => false,
            'is_for_all' => true,
            'is_filter' => false,
            'multiple' => false,
            'searchable' => false,
            'position' => 1,
        ];
    }
}
