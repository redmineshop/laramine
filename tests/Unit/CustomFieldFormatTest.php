<?php

namespace Tests\Unit;

use App\Domain\CustomFields\FieldFormatKey;
use App\Domain\CustomFields\FieldFormatRegistry;
use App\Domain\CustomFields\Formats\BoolFormat;
use App\Domain\CustomFields\Formats\DateFormat;
use App\Domain\CustomFields\Formats\FloatFormat;
use App\Domain\CustomFields\Formats\IntFormat;
use App\Domain\CustomFields\Formats\LinkFormat;
use App\Domain\CustomFields\Formats\ListFormat;
use App\Domain\CustomFields\Formats\ProgressbarFormat;
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

        $keys = [];
        foreach ($formats as $format) {
            $keys[] = $format->key();
            $this->assertTrue($format->isImplemented());
        }

        $this->assertSame(
            ['string', 'text', 'link', 'int', 'float', 'date', 'list', 'bool', 'enumeration', 'user', 'version', 'attachment', 'progressbar'],
            $keys,
        );

        foreach (FieldFormatKey::cases() as $case) {
            $format = $registry->get($case->value);
            $this->assertTrue($case->implemented());
            $this->assertTrue($format->isImplemented());
        }
    }

    public function test_link_uses_string_rules_and_stores_a_url_pattern(): void
    {
        $format = new LinkFormat;
        $field = new CustomField([
            'field_format' => 'link',
            'regexp' => '^https://',
            'min_length' => 12,
            'max_length' => 40,
            'format_store' => ['url_pattern' => 'https://links.test/%id%/%value%'],
        ]);

        $this->assertSame([], $format->validate($field, 'https://example.test/a', null));
        $this->assertSame(['https://example.test/a'], $format->serialize($field, 'https://example.test/a'));
        $this->assertSame('https://example.test/a', $format->cast($field, 'https://example.test/a'));
        $this->assertSame(
            'https://links.test/9/https://example.test/a',
            $format->formattedUrl($field, 'https://example.test/a', 9),
        );
        $this->assertNull($format->formattedUrl(new CustomField, 'https://example.test/a', 9));
        $this->assertSame(['Value does not match the pattern.'], $format->validate($field, 'http://example.test', null));
        $this->assertSame(['Value is shorter than the minimum length.'], $format->validate($field, 'https://x', null));
        $this->assertSame(['Value must be a string.'], $format->validate($field, 12, null));
        $this->assertSame(
            ['Multiple values are not supported for this format.'],
            $format->validate($field, ['https://a.example', 'https://b.example'], null),
        );
        $this->assertSame([], $format->validate($field, null, null));
        $this->assertFalse($format->supportsMultiple());
        $this->assertFalse($format->supportsSearchable());
        $this->assertFalse($format->supportsTotal());
        $this->assertSame('string', $format->queryFilterType());
        $this->assertSame(['url_pattern must be a string.'], $format->validateDefinition(new CustomField([
            'format_store' => ['url_pattern' => 12],
        ])));
        $this->assertSame([], $format->validateDefinition($field));
        $this->assertSame(
            ['url_pattern must use http, https, ftp, mailto, or a path.'],
            $format->validateDefinition(new CustomField([
                'format_store' => ['url_pattern' => 'javascript:alert(%value%)'],
            ])),
        );
        $this->assertSame('http://example.test/a', $format->outboundUrl(new CustomField, 'example.test/a', 1));
        $this->assertSame('https://already.test/a', $format->outboundUrl(new CustomField, 'https://already.test/a', 1));
        $this->assertSame('http://mailto:a@b.test', $format->outboundUrl(new CustomField, 'mailto:a@b.test', 1));
        $this->assertSame(
            'https://links.test/9/https://example.test/a',
            $format->outboundUrl($field, 'https://example.test/a', 9),
        );
    }

    public function test_link_formatted_url_encodes_tokens_and_keeps_the_pattern(): void
    {
        $format = new LinkFormat;
        $field = new CustomField([
            'field_format' => 'link',
            'regexp' => '^(\d+)-(.+)$',
            'format_store' => [
                'url_pattern' => 'https://ex.test/%project_identifier%/%m1%/%m2%?id=%id%&p=%project_id%#keep',
            ],
        ]);

        $this->assertSame(
            'https://ex.test/demo/12/a%20b?id=7&p=3#keep',
            $format->formattedUrl($field, '12-a b', 7, 3, 'demo'),
        );
        $this->assertSame(
            'https://ex.test/foo%20:bar',
            $format->formattedUrl(new CustomField([
                'format_store' => ['url_pattern' => 'https://ex.test/%value%'],
            ]), 'foo :bar', null),
        );
        $this->assertSame(
            'http://foo/bar#anchor',
            $format->formattedUrl(new CustomField([
                'format_store' => ['url_pattern' => 'http://foo/bar#anchor'],
            ]), '1', null),
        );
        $plain = new CustomField([
            'format_store' => ['url_pattern' => 'https://ex.test/%value%'],
        ]);
        $this->assertSame('https://ex.test/a+b', $format->formattedUrl($plain, 'a+b', 1));
        $this->assertSame('https://ex.test/100%25', $format->formattedUrl($plain, '100%', 1));
        $this->assertSame('https://ex.test/caf%C3%A9', $format->formattedUrl($plain, 'café', 1));
        $this->assertSame(
            'https://ex.test///',
            $format->formattedUrl(new CustomField([
                'format_store' => ['url_pattern' => 'https://ex.test/%project_id%/%project_identifier%/%id%'],
            ]), 'x', null),
        );
        $this->assertSame(
            'https://ex.test/',
            $format->formattedUrl(new CustomField([
                'regexp' => '[',
                'format_store' => ['url_pattern' => 'https://ex.test/%m1%'],
            ]), '12', 4),
        );
        $this->assertSame(
            'https://ex.test/42/42',
            $format->formattedUrl(new CustomField([
                'regexp' => '^(\d+)$',
                'format_store' => ['url_pattern' => 'https://ex.test/%m0%/%m1%'],
            ]), '42', 1),
        );
    }

    public function test_progressbar_is_an_integer_percent_on_a_step(): void
    {
        $format = new ProgressbarFormat;
        $field = new CustomField([
            'field_format' => 'progressbar',
            'format_store' => ['ratio_interval' => 10],
        ]);

        $this->assertSame([], $format->validate($field, '50', null));
        $this->assertSame(['50'], $format->serialize($field, '+050'));
        $this->assertSame(50, $format->cast($field, '50'));
        $this->assertSame(['0'], $format->serialize($field, 0));
        $this->assertSame(['100'], $format->serialize($field, '100'));
        $this->assertSame(['Value must be an integer from 0 to 100.'], $format->validate($field, '101', null));
        $this->assertSame(['Value must be an integer from 0 to 100.'], $format->validate($field, '-1', null));
        $this->assertSame(['Value must be an integer from 0 to 100.'], $format->validate($field, '1.5', null));
        $this->assertSame(['Value must be an integer from 0 to 100.'], $format->validate($field, true, null));
        $this->assertSame(['Value is not a multiple of the ratio interval.'], $format->validate($field, '15', null));
        $this->assertSame([], $format->validate(new CustomField(['field_format' => 'progressbar']), '15', null));
        $this->assertSame(
            ['Multiple values are not supported for this format.'],
            $format->validate($field, ['10', '20'], null),
        );
        $this->assertSame([], $format->validate($field, '', null));
        $this->assertFalse($format->supportsMultiple());
        $this->assertFalse($format->supportsSearchable());
        $this->assertFalse($format->supportsTotal());
        $this->assertSame('integer', $format->queryFilterType());
        $this->assertSame(
            ['ratio_interval must be a positive integer that divides 100.'],
            $format->validateDefinition(new CustomField([
                'format_store' => ['ratio_interval' => 7],
            ])),
        );
        $this->assertSame([], $format->validateDefinition($field));
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
        $this->assertTrue($format->supportsTotal());
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
        $this->assertTrue($format->supportsTotal());
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
        $this->assertSame('list_optional_with_history', $format->queryFilterType());
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
        $this->assertSame('list_optional_with_history', $format->queryFilterType());
    }
}
