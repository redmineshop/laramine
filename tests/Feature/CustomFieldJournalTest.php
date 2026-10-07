<?php

namespace Tests\Feature;

use App\Domain\CustomFields\CustomFieldService;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Issues\IssueService;
use App\Models\Journal;
use App\Models\JournalDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * Issue updates record custom-value diffs on journal_details.
 *
 * These tests are Laramine behavior on MySQL. The pin comparison is
 * tests/Parity/CustomFieldParityTest.php.
 */
class CustomFieldJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_records_custom_field_diffs_and_ignores_unchanged_values(): void
    {
        $world = DomainFixture::boot('cf-journal');
        $world->join();
        $fields = app(CustomFieldService::class);
        $string = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Customer',
            'field_format' => 'string',
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);
        $multi = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Tags',
            'field_format' => 'list',
            'possible_values' => ['alpha', 'beta'],
            'multiple' => true,
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
        ]);
        $issues = app(IssueService::class);
        $issue = $issues->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Tracked',
            'custom_fields' => [
                ['id' => $string->id, 'value' => 'pin'],
                ['id' => $multi->id, 'value' => ['alpha', 'beta']],
            ],
        ]);
        $this->assertSame(0, Journal::query()->where('journalized_id', $issue->id)->count());

        $issues->update($world->user, $issue, ['notes' => 'only a note']);
        $note = Journal::query()->where('journalized_id', $issue->id)->first();
        $this->assertInstanceOf(Journal::class, $note);
        $this->assertSame(0, $note->details()->count());

        $issues->update($world->user, $issue->fresh() ?? $issue, [
            'subject' => 'Renamed',
            'custom_fields' => [
                ['id' => $string->id, 'value' => 'next'],
                ['id' => $multi->id, 'value' => ['alpha', 'beta']],
            ],
        ]);
        $mixed = $this->latest($issue->id);
        $details = $mixed->details()->orderBy('id')->get();
        $this->assertCount(2, $details);
        $subject = $details[0];
        $custom = $details[1];
        $this->assertInstanceOf(JournalDetail::class, $subject);
        $this->assertInstanceOf(JournalDetail::class, $custom);
        $this->assertSame(IssueJournalWriter::PROPERTY_ATTR, (string) $subject->property);
        $this->assertSame('subject', (string) $subject->prop_key);
        $this->assertSame('Tracked', $subject->old_value);
        $this->assertSame('Renamed', $subject->value);
        $this->assertSame(IssueJournalWriter::PROPERTY_CF, (string) $custom->property);
        $this->assertSame((string) $string->id, (string) $custom->prop_key);
        $this->assertSame('pin', $custom->old_value);
        $this->assertSame('next', $custom->value);

        $issues->update($world->user, $issue->fresh() ?? $issue, [
            'custom_fields' => [[
                'id' => $multi->id,
                'value' => ['beta'],
            ]],
        ]);
        $multiDetail = $this->latest($issue->id)->details()->first();
        $this->assertInstanceOf(JournalDetail::class, $multiDetail);
        $this->assertSame(IssueJournalWriter::PROPERTY_CF, (string) $multiDetail->property);
        $this->assertSame((string) $multi->id, (string) $multiDetail->prop_key);
        $this->assertSame('alpha,beta', $multiDetail->old_value);
        $this->assertSame('beta', $multiDetail->value);

        $issues->update($world->user, $issue->fresh() ?? $issue, [
            'custom_fields' => [[
                'id' => $string->id,
                'value' => null,
            ]],
        ]);
        $cleared = $this->latest($issue->id)->details()->first();
        $this->assertInstanceOf(JournalDetail::class, $cleared);
        $this->assertSame('next', $cleared->old_value);
        $this->assertNull($cleared->value);
    }

    private function latest(int $issueId): Journal
    {
        $journal = Journal::query()->where('journalized_id', $issueId)->orderByDesc('id')->first();
        $this->assertInstanceOf(Journal::class, $journal);

        return $journal;
    }
}
