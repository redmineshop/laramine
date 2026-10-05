<?php

namespace Tests\Feature;

use App\Domain\CustomFields\CustomFieldEnumerationService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\CustomFields\CustomFieldValidationException;
use App\Domain\DomainException;
use App\Domain\Issues\IssueService;
use App\Models\CustomFieldEnumeration;
use App\Models\CustomValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

class CustomFieldEnumerationOptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_enumeration_options_reorder_and_values_stay_on_the_same_ids(): void
    {
        $world = DomainFixture::boot('cf-enum-crud');
        $world->join();
        $fields = app(CustomFieldService::class);
        $options = app(CustomFieldEnumerationService::class);
        $issues = app(IssueService::class);
        $tracker = [$world->tracker->id];

        $text = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Note',
            'field_format' => 'string',
            'is_for_all' => true,
            'tracker_ids' => $tracker,
        ]);
        try {
            $options->create($text, 'Nope');
            $this->fail('Only an enumeration field can own options.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('enumeration', $exception->getMessage());
        }

        $field = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Kind',
            'field_format' => 'enumeration',
            'multiple' => true,
            'is_for_all' => true,
            'tracker_ids' => $tracker,
        ]);

        try {
            $options->create($field, '   ');
            $this->fail('A blank enumeration name must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('required', $exception->getMessage());
        }

        $alpha = $options->create($field, ' Alpha ');
        $beta = $options->create($field, 'Beta');
        $this->assertSame('Alpha', $alpha->name);
        $this->assertTrue($alpha->active);
        $this->assertSame(1, (int) $alpha->position);
        $this->assertSame(2, (int) $beta->position);

        try {
            $options->create($field, 'alpha');
            $this->fail('Enumeration names must be unique for the field.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }
        $this->assertSame(2, CustomFieldEnumeration::query()->where('custom_field_id', $field->id)->count());

        $fields->save([
            'id' => $field->id,
            'default_value' => (string) $alpha->id,
        ]);
        try {
            $options->setActive($alpha->fresh() ?? $alpha, false);
            $this->fail('The default option cannot be deactivated.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('default', $exception->getMessage());
        }
        $this->assertTrue(($alpha->fresh() ?? $alpha)->active);

        $fields->save([
            'id' => $field->id,
            'default_value' => (string) $beta->id,
        ]);

        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Kinds',
            'custom_fields' => [
                ['id' => $field->id, 'value' => [(string) $alpha->id, (string) $beta->id]],
            ],
        ]);

        $renamed = $options->rename($alpha->fresh() ?? $alpha, 'Alpha renamed');
        $this->assertSame('Alpha renamed', $renamed->name);
        $reordered = $options->reorder($field, [(int) $beta->id, (int) $alpha->id]);
        $this->assertSame([(int) $beta->id, (int) $alpha->id], array_map(
            static fn (CustomFieldEnumeration $row): int => (int) $row->id,
            $reordered,
        ));
        $this->assertSame(1, (int) ($beta->fresh() ?? $beta)->position);
        $this->assertSame(2, (int) ($alpha->fresh() ?? $alpha)->position);

        $stored = CustomValue::query()
            ->where('custom_field_id', $field->id)
            ->where('customized_id', $issue->id)
            ->orderBy('id')
            ->pluck('value')
            ->all();
        $this->assertSame([(string) $alpha->id, (string) $beta->id], $stored);
        $this->assertSame('Alpha renamed', ($alpha->fresh() ?? $alpha)->name);

        $withDefault = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Default kind',
        ]);
        $this->assertSame(
            (string) $beta->id,
            CustomValue::query()->where('custom_field_id', $field->id)->where('customized_id', $withDefault->id)->value('value'),
        );

        $options->setActive($alpha->fresh() ?? $alpha, false);
        $issues->update($world->user, $issue->fresh(), [
            'subject' => 'Kinds kept',
        ]);
        $kept = CustomValue::query()
            ->where('custom_field_id', $field->id)
            ->where('customized_id', $issue->id)
            ->orderBy('id')
            ->pluck('value')
            ->all();
        $this->assertSame([(string) $alpha->id, (string) $beta->id], $kept);

        try {
            $options->reorder($field, [(int) $beta->id]);
            $this->fail('Reorder must include inactive options.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('every enumeration', $exception->getMessage());
        }
        $this->assertSame(1, (int) ($beta->fresh() ?? $beta)->position);

        try {
            $issues->update($world->user, $issue->fresh(), [
                'custom_fields' => [
                    ['id' => $field->id, 'value' => [(string) $alpha->id]],
                ],
            ]);
            $this->fail('Inactive enumerations must be rejected.');
        } catch (CustomFieldValidationException $exception) {
            $this->assertStringContainsString('not active', $exception->getMessage());
        }

        $issues->update($world->user, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $field->id, 'value' => [(string) $beta->id]],
            ],
        ]);
        $this->assertSame(
            [(string) $beta->id],
            CustomValue::query()->where('custom_field_id', $field->id)->where('customized_id', $issue->id)->orderBy('id')->pluck('value')->all(),
        );

        $options->setActive($alpha->fresh() ?? $alpha, true);
        $issues->update($world->user, $issue->fresh(), [
            'custom_fields' => [
                ['id' => $field->id, 'value' => [(string) $alpha->id, (string) $beta->id]],
            ],
        ]);
        $this->assertSame(
            [(string) $alpha->id, (string) $beta->id],
            CustomValue::query()->where('custom_field_id', $field->id)->where('customized_id', $issue->id)->orderBy('id')->pluck('value')->all(),
        );
    }
}
