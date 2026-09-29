<?php

namespace Tests\Unit;

use App\Domain\CustomFields\FieldFormatKey;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Domain\CustomFields\Formats\BoolFormat;
use App\Domain\CustomFields\Formats\DateFormat;
use App\Domain\CustomFields\Formats\FloatFormat;
use App\Domain\CustomFields\Formats\IntFormat;
use App\Domain\CustomFields\Formats\ListFormat;
use App\Domain\CustomFields\Formats\StringFormat;
use App\Domain\CustomFields\Formats\TextFormat;
use App\Models\CustomField;
use Tests\TestCase;

class CustomFieldFormatTest extends TestCase
{
    public function test_registry_recognizes_every_redmine_format_key(): void
    {
        $registry = app(FieldFormatRegistry::class);
        $formats = $registry->all();
        $this->assertCount(13, $formats);

        $implemented = [];
        $deferred = [];
        foreach ($formats as $format) {
            if ($format->isImplemented()) {
                $implemented[] = $format->key();
            } else {
                $deferred[] = $format->key();
            }
        }

        $this->assertSame(
            ['string', 'text', 'int', 'float', 'date', 'list', 'bool', 'user', 'version'],
            $implemented,
        );
        $this->assertSame(['link', 'enumeration', 'attachment', 'progressbar'], $deferred);

        foreach (FieldFormatKey::cases() as $case) {
            $format = $registry->get($case->value);
            $this->assertSame($case->implemented(), $format->isImplemented());
            if (! $format->isImplemented()) {
                $field = new CustomField(['field_format' => $case->value]);
                $this->assertSame(
                    ['The '.$case->value.' format is not supported.'],
                    $format->validate($field, 'x', null),
                );
                $this->assertSame([], $format->validate($field, null, null));
            }
        }
    }

    public function test_string_and_text_round_trip_and_reject_bad_values(): void
    {
        $string = new StringFormat;
        $text = new TextFormat;
        $field = new CustomField([
            'field_format' => 'string',
            'regexp' => '^\d+$',
            'min_length' => 2,
            'max_length' => 4,
        ]);

        $this->assertSame([], $string->validate($field, '123', null));
        $this->assertSame(['123'], $string->serialize($field, '123'));
        $this->assertSame('123', $string->cast($field, '123'));
        $this->assertSame(['Value does not match the pattern.'], $string->validate($field, '12a', null));
        $this->assertSame(['Value is shorter than the minimum length.'], $string->validate($field, '1', null));
        $this->assertSame(['Value is longer than the maximum length.'], $string->validate(new CustomField([
            'max_length' => 2,
        ]), 'abcd', null));
        $this->assertSame(['Value must be a string.'], $string->validate($field, 12, null));
        $this->assertSame(
            ['Multiple values are not supported for this format.'],
            $string->validate($field, ['12', '34'], null),
        );
        $this->assertFalse($string->supportsMultiple());
        $this->assertTrue($string->supportsSearchable());
        $this->assertSame('string', $string->queryFilterType());
        $this->assertSame('text', $text->queryFilterType());
        $this->assertTrue($text->supportsSearchable());

        $broken = new CustomField(['regexp' => '[']);
        $this->assertSame(['Pattern is not a valid regular expression.'], $string->validateDefinition($broken));
    }

    public function test_int_round_trip_and_rejects_non_integers(): void
    {
        $format = new IntFormat;
        $field = new CustomField(['field_format' => 'int']);

        $this->assertSame([], $format->validate($field, '+007', null));
        $this->assertSame(['7'], $format->serialize($field, '+007'));
        $this->assertSame(7, $format->cast($field, '7'));
        $this->assertSame(['-3'], $format->serialize($field, -3));
        $this->assertSame(-3, $format->cast($field, '-3'));
        $this->assertSame(['0'], $format->serialize($field, '0'));
        $this->assertSame(['Value is not a valid integer.'], $format->validate($field, '1.5', null));
        $this->assertSame(['Value is not a valid integer.'], $format->validate($field, '1e2', null));
        $this->assertSame(['Value is not a valid integer.'], $format->validate($field, true, null));
        $this->assertSame(
            ['Value is not a valid integer.'],
            $format->validate($field, '999999999999999999999', null),
        );
        $this->assertSame([], $format->validate($field, null, null));
        $this->assertSame([], $format->serialize($field, ''));
        $this->assertFalse($format->supportsMultiple());
        $this->assertFalse($format->supportsSearchable());
        $this->assertSame('integer', $format->queryFilterType());
    }

    public function test_float_round_trip_and_rejects_non_floats(): void
    {
        $format = new FloatFormat;
        $field = new CustomField(['field_format' => 'float']);

        $this->assertSame([], $format->validate($field, '1.50', null));
        $this->assertSame(['1.50'], $format->serialize($field, '1.50'));
        $this->assertSame(1.5, $format->cast($field, '1.50'));
        $this->assertSame(['.5'], $format->serialize($field, '.5'));
        $this->assertSame(['-2'], $format->serialize($field, -2));
        $this->assertSame(['Value is not a valid float.'], $format->validate($field, '1e2', null));
        $this->assertSame(['Value is not a valid float.'], $format->validate($field, 'abc', null));
        $this->assertSame(['Value is not a valid float.'], $format->validate($field, true, null));
        $this->assertSame('float', $format->queryFilterType());
        $this->assertFalse($format->supportsSearchable());
    }

    public function test_date_round_trip_and_offset_default(): void
    {
        $format = new DateFormat;
        $field = new CustomField(['field_format' => 'date']);

        $this->assertSame([], $format->validate($field, '2026-09-01', null));
        $this->assertSame(['2026-09-01'], $format->serialize($field, '2026-09-01'));
        $this->assertSame('2026-09-01', $format->cast($field, '2026-09-01'));
        $this->assertSame(['Value is not a valid date (YYYY-MM-DD).'], $format->validate($field, '2026-02-31', null));
        $this->assertSame(['Value is not a valid date (YYYY-MM-DD).'], $format->validate($field, '2026-9-1', null));
        $this->assertSame('date', $format->queryFilterType());

        $offset = new CustomField([
            'field_format' => 'date',
            'default_value' => '2',
            'format_store' => ['default_value_mode' => 'date_offset'],
        ]);
        $this->assertSame([], $format->validateDefinition($offset));
        $badOffset = new CustomField([
            'default_value' => 'tomorrow',
            'format_store' => ['default_value_mode' => 'date_offset'],
        ]);
        $this->assertSame(
            ['Date offset default must be an integer number of days.'],
            $format->validateDefinition($badOffset),
        );
    }

    public function test_list_round_trip_multiple_and_rejection(): void
    {
        $format = new ListFormat;
        $single = new CustomField([
            'field_format' => 'list',
            'multiple' => false,
            'possible_values' => ['A', 'B'],
        ]);
        $multiple = new CustomField([
            'field_format' => 'list',
            'multiple' => true,
            'possible_values' => ['A', 'B'],
        ]);

        $this->assertSame([], $format->validate($single, 'A', null));
        $this->assertSame(['A'], $format->serialize($single, 'A'));
        $this->assertSame('A', $format->cast($single, 'A'));
        $this->assertSame(['Value is not in the list of possible values.'], $format->validate($single, 'C', null));
        $this->assertSame(
            ['Multiple values are not supported for this format.'],
            $format->validate($single, ['A', 'B'], null),
        );
        $this->assertSame([], $format->validate($multiple, ['B', 'A'], null));
        $this->assertSame(['B', 'A'], $format->serialize($multiple, ['B', 'A']));
        $this->assertSame(['Value is repeated.'], $format->validate($multiple, ['A', 'A'], null));
        $this->assertSame(['List fields require possible_values.'], $format->validateDefinition(new CustomField([
            'possible_values' => [],
        ])));
        $this->assertTrue($format->supportsMultiple());
        $this->assertTrue($format->supportsSearchable());
        $this->assertSame('list_optional', $format->queryFilterType());
    }

    public function test_bool_stores_one_and_zero(): void
    {
        $format = new BoolFormat;
        $field = new CustomField(['field_format' => 'bool']);

        $this->assertSame(['1'], $format->serialize($field, true));
        $this->assertSame(['0'], $format->serialize($field, false));
        $this->assertSame(['1'], $format->serialize($field, '1'));
        $this->assertSame(['0'], $format->serialize($field, 0));
        $this->assertTrue($format->cast($field, '1'));
        $this->assertFalse($format->cast($field, '0'));
        $this->assertSame(['Value must be 1 or 0.'], $format->validate($field, 'yes', null));
        $this->assertSame(['Value must be 1 or 0.'], $format->validate($field, 'true', null));
        $this->assertSame([], $format->serialize($field, null));
        $this->assertFalse($format->supportsMultiple());
        $this->assertSame('list_optional', $format->queryFilterType());
    }
}
