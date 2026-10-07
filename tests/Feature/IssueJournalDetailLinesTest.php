<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\CustomFieldService;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\Issues\History\JournalActionView;
use App\Domain\Issues\IssueService;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * History lines for attribute, custom-field, attachment, and relation details.
 *
 * The pin comparison is tests/Parity/JournalParityTest.php.
 */
class IssueJournalDetailLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_field_lines_use_the_field_name_and_hide_invisible_fields(): void
    {
        $world = DomainFixture::boot('journal-lines');
        $world->join();
        $fields = app(CustomFieldService::class);
        $string = $this->field($fields, $world, 'Customer', 'string');
        $hidden = $fields->save([
            'type' => 'IssueCustomField',
            'name' => 'Secret',
            'field_format' => 'string',
            'visible' => false,
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
        $flag = $this->field($fields, $world, 'Flag', 'bool');
        $text = $this->field($fields, $world, 'Body', 'text');
        $bar = $this->field($fields, $world, 'Bar', 'progressbar', ['ratio_interval' => 10]);
        $fileField = $this->field($fields, $world, 'File field', 'attachment');
        $before = Attachment::query()->create([
            'author_id' => $world->user->id,
            'filename' => 'before.pdf',
            'disk_filename' => 'before.pdf',
            'filesize' => 4,
        ]);
        $after = Attachment::query()->create([
            'author_id' => $world->user->id,
            'filename' => 'after.pdf',
            'disk_filename' => 'after.pdf',
            'filesize' => 4,
        ]);

        $issue = app(IssueService::class)->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Lines',
        ]);
        $stranger = User::factory()->create();
        $other = app(IssueService::class)->create($world->user, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Other',
        ]);
        $other->forceFill([
            'is_private' => true,
            'author_id' => $stranger->id,
            'assigned_to_id' => null,
        ])->save();
        $journal = $this->journal($issue, $world->user, null, false);
        $this->detail($journal, 'attr', 'description', 'old', 'new');
        $this->detail($journal, 'attr', 'is_private', '0', '1');
        $this->detail($journal, 'attr', 'parent_id', null, (string) $other->id);
        $this->detail($journal, 'cf', (string) $string->id, 'pin', 'next');
        $this->detail($journal, 'cf', (string) $hidden->id, 'nope', 'still');
        $this->detail($journal, 'cf', (string) $multi->id, 'alpha', 'alpha,beta');
        $this->detail($journal, 'cf', (string) $flag->id, '0', '1');
        $this->detail($journal, 'cf', (string) $text->id, 'old body', 'new body');
        $this->detail($journal, 'cf', (string) $bar->id, '40', '50');
        $this->detail($journal, 'cf', (string) $fileField->id, (string) $before->id, (string) $after->id);
        $this->detail($journal, 'cf', '999', 'a', 'b');
        $this->detail($journal, 'attachment', '1', null, 'spec.pdf');
        $this->detail($journal, 'attachment', '2', 'draft.pdf', null);
        $this->detail($journal, 'relation', 'relates', null, (string) $issue->id);
        $this->detail($journal, 'relation', 'blocks', null, (string) $other->id);

        $added = $this->journal($issue, $world->user, null, false);
        $this->detail($added, 'cf', (string) $multi->id, null, 'beta');

        $private = $this->journal($issue, $world->user, 'Hidden from the author.', true);

        $show = app(IssueHistoryPresenter::class)->present($world->user, $issue->fresh() ?? $issue);
        $lines = array_map(static fn ($line) => $line->text, $show->historyEntries[0]->propertyChanges);
        $this->assertSame([
            'Description updated',
            'Private changed from No to Yes',
            'Parent task set to #'.$other->id,
            'Customer changed from pin to next',
            'Tags changed from alpha to alpha, beta',
            'Flag changed from No to Yes',
            'Body updated',
            'Bar changed from 40% to 50%',
            'File field changed from before.pdf to after.pdf',
            'File spec.pdf added',
            'File deleted (draft.pdf)',
            'Related to Bug #'.$issue->id.': Lines added',
        ], $lines);
        $this->assertNotContains('Secret changed from nope to still', $lines);
        $this->assertSame('Tags beta added', $show->historyEntries[1]->propertyChanges[0]->text);
        $this->assertSame('Tags <em>beta</em> added', $show->historyEntries[1]->propertyChanges[0]->html);
        $this->assertSame('File deleted (<em>draft.pdf</em>)', $show->historyEntries[0]->propertyChanges[10]->html);
        $ids = array_map(static fn ($entry) => $entry->journalId, $show->historyEntries);
        $this->assertNotContains((int) $private->id, $ids);

        $manager = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Manager',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues', 'view_private_notes', 'edit_issue_notes', 'add_issue_notes'],
            'issues_visibility' => 'all',
        ]);
        app(MembershipService::class)->assignRole($world->project, $manager, $role);
        $seen = app(IssueHistoryPresenter::class)->present($manager, $issue->fresh() ?? $issue);
        $managerLines = array_map(static fn ($line) => $line->text, $seen->historyEntries[0]->propertyChanges);
        $this->assertContains('Blocks Bug #'.$other->id.': Other added', $managerLines);
        $this->assertNotContains('Secret changed from nope to still', $managerLines);
        $privateEntry = null;
        foreach ($seen->historyEntries as $entry) {
            if ($entry->journalId === (int) $private->id) {
                $privateEntry = $entry;
            }
        }
        $this->assertNotNull($privateEntry);
        $this->assertSame('Hidden from the author.', $privateEntry->noteText);
        $this->assertTrue($this->hasAction($privateEntry->actions, 'edit'));
        $this->assertFalse($this->hasAction($show->historyEntries[0]->actions, 'quote'));
    }

    /**
     * @param  array<string, mixed>  $store
     */
    private function field(CustomFieldService $fields, DomainFixture $world, string $name, string $format, array $store = []): CustomField
    {
        return $fields->save([
            'type' => 'IssueCustomField',
            'name' => $name,
            'field_format' => $format,
            'is_for_all' => true,
            'tracker_ids' => [$world->tracker->id],
            'format_store' => $store === [] ? null : $store,
        ]);
    }

    private function journal(Issue $issue, User $user, ?string $notes, bool $privateNotes): Journal
    {
        $journal = new Journal;
        $journal->timestamps = false;
        $journal->forceFill([
            'journalized_type' => 'Issue',
            'journalized_id' => $issue->id,
            'user_id' => $user->id,
            'notes' => $notes,
            'private_notes' => $privateNotes,
            'created_on' => now(),
            'updated_on' => null,
        ]);
        $journal->save();

        return $journal;
    }

    private function detail(Journal $journal, string $property, string $key, ?string $old, ?string $new): void
    {
        JournalDetail::query()->create([
            'journal_id' => $journal->id,
            'property' => $property,
            'prop_key' => $key,
            'old_value' => $old,
            'value' => $new,
        ]);
    }

    /**
     * @param  list<JournalActionView>  $actions
     */
    private function hasAction(array $actions, string $key): bool
    {
        foreach ($actions as $action) {
            if ($action->key === $key) {
                return true;
            }
        }

        return false;
    }
}
