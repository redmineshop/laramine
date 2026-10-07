<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\Attachments\AttachmentArchive;
use App\Domain\Attachments\AttachmentService;
use App\Domain\DomainException;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\Issues\History\JournalActionList;
use App\Domain\Issues\History\JournalEntryView;
use App\Domain\Issues\History\JournalMenuItemView;
use App\Domain\Issues\IssueService;
use App\Domain\PermissionDeniedException;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\SettingValue;
use App\Models\Changeset;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\Repository;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * Journals leftovers: download-all, thumbnail notes, the absolute copy link,
 * and the spent-time and associated-revisions tabs.
 *
 * This is Laramine behavior. It does not compare rows with a Redmine 7.0.1
 * database. Parity stays NOT VERIFIED.
 */
class IssueJournalHistoryLeftoversTest extends TestCase
{
    use RefreshDatabase;

    public function test_thumbnail_only_journal_is_kept_on_notes_when_thumbnails_are_enabled(): void
    {
        $world = DomainFixture::boot('journal-thumbs');
        $admin = User::factory()->create(['admin' => true, 'login' => 'thumbs-admin']);
        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);
        $files = app(AttachmentService::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Thumbnails',
            'status_id' => $world->newStatus->id,
        ]);
        $image = $this->storeJournal($issue->id, (int) $admin->id, null);
        $pdf = $this->storeJournal($issue->id, (int) $admin->id, null);
        $issues->update($admin, $issue->fresh(), ['subject' => 'Thumbnails renamed']);
        $detail = Journal::query()->where('journalized_id', $issue->id)->orderByDesc('id')->first();
        $this->assertInstanceOf(Journal::class, $detail);

        $paths = [];
        $paths[] = $files->absolutePath($files->store($admin, 'shot.PNG', 'PNGDATA', 'text/plain', null, $image));
        $paths[] = $files->absolutePath($files->store($admin, 'notes.pdf', 'PDF', 'application/pdf', null, $pdf));
        $paths[] = $files->absolutePath($files->store($admin, 'also.png', 'ALSO', 'image/png', null, $detail));

        try {
            $this->setting(SettingValue::THUMBNAILS_ENABLED, '1');
            $show = $history->present($admin, $issue->fresh());

            $this->assertSame(
                [IssueHistoryPresenter::TAB_HISTORY, IssueHistoryPresenter::TAB_NOTES, IssueHistoryPresenter::TAB_PROPERTIES],
                $show->historyTabLabels,
            );
            $this->assertSame([$image->id, $detail->id], $this->ids($show->notesEntries));
            $this->assertNotContains($pdf->id, $this->ids($show->notesEntries));
            $this->assertSame([$detail->id], $this->ids($show->propertyChangeEntries));

            $thumb = $show->notesEntries[0];
            $this->assertFalse($thumb->hasNote);
            $this->assertTrue($thumb->hasThumbnails);
            $this->assertSame('shot.PNG', $thumb->attachments[0]->filename);
            $this->assertTrue($thumb->attachments[0]->thumbnailable);
            $this->assertSame(100, $thumb->attachments[0]->thumbnailSize);
            $this->assertSame('text/plain', $thumb->attachments[0]->contentType);
            $this->assertSame(['reaction', 'more'], $this->keys($thumb));

            $pdfEntry = $show->historyEntries[1];
            $this->assertFalse($pdfEntry->hasThumbnails);
            $this->assertFalse($pdfEntry->attachments[0]->thumbnailable);
            $this->assertSame('notes.pdf', $pdfEntry->attachments[0]->filename);

            $onProperties = $show->propertyChangeEntries[0];
            $this->assertSame(['reaction'], $this->keys($onProperties));
            $this->assertTrue($onProperties->attachments[0]->thumbnailable);
            $this->assertNull($onProperties->noteText);

            $this->setting(SettingValue::THUMBNAILS_ENABLED, '0');
            $disabled = $history->present($admin, $issue->fresh());
            $this->assertSame(
                [IssueHistoryPresenter::TAB_HISTORY, IssueHistoryPresenter::TAB_PROPERTIES],
                $disabled->historyTabLabels,
            );
            $this->assertSame([], $disabled->notesEntries);
            $this->assertFalse($disabled->historyEntries[0]->hasThumbnails);
            $this->assertFalse($disabled->historyEntries[0]->attachments[0]->thumbnailable);
        } finally {
            $this->unlinkAll($paths);
        }
    }

    public function test_download_all_files_zips_issue_and_journal_attachments(): void
    {
        $world = DomainFixture::boot('journal-zip');
        $world->join();
        $viewer = $world->user;
        $author = User::factory()->create([
            'login' => 'zip-author',
            'firstname' => 'Zip',
            'lastname' => 'Author',
        ]);
        $this->member($world, $author, 'zip_author', [
            'view_issues',
            'add_issues',
            'edit_issues',
            'add_issue_notes',
            'set_notes_private',
            'view_private_notes',
        ]);
        $issues = app(IssueService::class);
        $files = app(AttachmentService::class);
        $archive = app(AttachmentArchive::class);
        $history = app(IssueHistoryPresenter::class);
        $issue = $issues->create($author, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Files',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($author, $issue, [
            'notes' => 'See the files.',
            'private_notes' => true,
        ]);
        $journal = Journal::query()->where('journalized_id', $issue->id)->first();
        $this->assertInstanceOf(Journal::class, $journal);

        $paths = [];
        $paths[] = $files->absolutePath($files->store($author, 'note.txt', 'ALPHA', null, null, $journal));
        $paths[] = $files->absolutePath($files->store($author, 'note.txt', 'BETA', null, null, $journal));
        $paths[] = $files->absolutePath($files->store($author, 'one.txt', 'ONE', null, null, $issue));
        $alone = $files->store($author, 'only.txt', 'ONLY', null, null, $issue);
        $paths[] = $files->absolutePath($alone);

        try {
            $show = $history->present($author, $issue->fresh());
            $note = $show->historyEntries[0];
            $this->assertSame(
                [JournalActionList::DOWNLOAD_ALL, JournalActionList::COPY_LINK],
                $this->menuLabels($note),
            );
            $download = $this->menu($note)[0];
            $this->assertSame('Journal', $download->containerType);
            $this->assertSame($journal->id, $download->containerId);
            $this->assertNotNull($show->issueDownloadAll);
            $this->assertSame('Issue', $show->issueDownloadAll->containerType);
            $this->assertSame($issue->id, $show->issueDownloadAll->containerId);
            $this->assertCount(2, $show->issueAttachments);
            $this->assertSame(['one.txt', 'only.txt'], [
                $show->issueAttachments[0]->filename,
                $show->issueAttachments[1]->filename,
            ]);

            $journalZip = $archive->downloadAll($author, $journal);
            $this->assertSame('journal-'.$journal->id.'.zip', $journalZip->filename);
            $this->assertSame(
                ['note.txt' => 'ALPHA', 'note(2).txt' => 'BETA'],
                $this->zipEntries($journalZip->contents),
            );

            $issueZip = $archive->downloadAll($viewer, $issue);
            $this->assertSame('issue-'.$issue->id.'.zip', $issueZip->filename);
            $this->assertSame(
                ['one.txt' => 'ONE', 'only.txt' => 'ONLY'],
                $this->zipEntries($issueZip->contents),
            );

            $outsider = User::factory()->create(['login' => 'zip-outsider']);
            try {
                $archive->downloadAll($outsider, $issue);
                $this->fail('An outsider can see the issue zip.');
            } catch (PermissionDeniedException $denied) {
                $this->assertSame('view_issues', $denied->permission);
            }

            try {
                $archive->downloadAll($viewer, $journal);
                $this->fail('A public viewer can download a private journal.');
            } catch (PermissionDeniedException $denied) {
                $this->assertSame('view_private_notes', $denied->permission);
            }

            $single = $issues->create($author, $world->project, [
                'tracker_id' => $world->tracker->id,
                'subject' => 'One file',
                'status_id' => $world->newStatus->id,
            ]);
            $singleFile = $files->store($author, 'solo.txt', 'SOLO', null, null, $single);
            $paths[] = $files->absolutePath($singleFile);
            $singleShow = $history->present($author, $single->fresh());
            $this->assertNull($singleShow->issueDownloadAll);
            try {
                $archive->downloadAll($author, $single);
                $this->fail('One attachment was zipped.');
            } catch (DomainException $rejected) {
                $this->assertNotInstanceOf(PermissionDeniedException::class, $rejected);
            }
        } finally {
            $this->unlinkAll($paths);
        }
    }

    public function test_copy_link_is_an_absolute_issue_url(): void
    {
        $world = DomainFixture::boot('journal-copy');
        $admin = User::factory()->create(['admin' => true, 'login' => 'copy-admin']);
        $public = $this->member($world, User::factory()->create(['login' => 'copy-public']), 'copy_public', [
            'view_issues',
            'add_issue_notes',
        ]);
        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Copy',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $issue, [
            'notes' => 'Hidden first.',
            'private_notes' => true,
        ]);
        $issues->update($admin, $issue->fresh(), ['notes' => 'Visible second.']);

        $defaultShow = $history->present($admin, $issue->fresh());
        $this->assertSame(
            'http://localhost:3000/issues/'.$issue->id.'#note-2',
            $this->menu($defaultShow->historyEntries[1])[0]->fragment,
        );
        $this->assertSame('#note-2', $defaultShow->historyEntries[1]->anchorHref);

        $this->setting(SettingValue::PROTOCOL, 'https');
        $this->setting(SettingValue::HOST_NAME, 'tracker.example:8443');
        $adminShow = $history->present($admin, $issue->fresh());
        $publicShow = $history->present($public, $issue->fresh());

        $this->assertSame(
            'https://tracker.example:8443/issues/'.$issue->id.'#note-2',
            $this->menu($adminShow->historyEntries[1])[0]->fragment,
        );
        $this->assertCount(1, $publicShow->historyEntries);
        $this->assertSame('#note-1', $publicShow->historyEntries[0]->anchorHref);
        $this->assertSame(
            'https://tracker.example:8443/issues/'.$issue->id.'#note-1',
            $this->menu($publicShow->historyEntries[0])[0]->fragment,
        );
        $this->assertSame('Visible second.', $publicShow->historyEntries[0]->noteText);
    }

    public function test_spent_time_and_associated_revisions_tabs_follow_permission(): void
    {
        $world = DomainFixture::boot('journal-sides');
        $projects = app(ProjectService::class);
        $projects->enableModule($world->project, 'time_tracking');
        $projects->enableModule($world->project, 'repository');
        $ada = User::factory()->create([
            'login' => 'ada',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
        ]);
        $bea = User::factory()->create([
            'login' => 'bea',
            'firstname' => 'Bea',
            'lastname' => 'Coder',
        ]);
        $own = $this->member($world, $ada, 'time_own', [
            'view_issues',
            'view_time_entries',
            'view_changesets',
        ], 'own');
        $wide = Role::query()->create([
            'name' => 'time_all_without_permission',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues'],
            'issues_visibility' => 'default',
            'time_entries_visibility' => 'all',
        ]);
        app(MembershipService::class)->assignRole($world->project, $ada, $wide);
        $reader = $this->member($world, $bea, 'time_all', [
            'view_issues',
            'add_issues',
            'add_issue_notes',
            'view_time_entries',
            'view_changesets',
        ], 'all');
        $blocked = $this->member($world, User::factory()->create(['login' => 'no-side']), 'no_side', [
            'view_issues',
            'add_issue_notes',
        ]);

        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);
        $issue = $issues->create($reader, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Sides',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($reader, $issue, ['notes' => 'A note.']);
        $this->logTime($world->project, $issue, $ada, $reader, 1.5, '2026-10-01', 'Ada work');
        $this->logTime($world->project, $issue, $bea, $reader, 2, '2026-10-03', 'Bea work');
        $this->linkRevision($world->project, $issue, 'a1', 'First fix', '2026-10-01 09:00:00', $ada);
        $this->linkRevision($world->project, $issue, 'b2', 'Second fix', '2026-10-04 11:30:00', null);

        $show = $history->present($reader, $issue->fresh());
        $this->assertSame(
            [
                IssueHistoryPresenter::TAB_HISTORY,
                IssueHistoryPresenter::TAB_NOTES,
                IssueHistoryPresenter::TAB_SPENT_TIME,
                IssueHistoryPresenter::TAB_REVISIONS,
            ],
            $show->historyTabLabels,
        );
        $this->assertSame(['2026-10-03', '2026-10-01'], [
            $show->timeEntries[0]->spentOn,
            $show->timeEntries[1]->spentOn,
        ]);
        $this->assertSame(2.0, $show->timeEntries[0]->hours);
        $this->assertSame('Bea Coder', $show->timeEntries[0]->userName);
        $this->assertSame('Development', $show->timeEntries[0]->activityName);
        $this->assertSame('Bea work', $show->timeEntries[0]->comments);
        $this->assertSame(1.5, $show->timeEntries[1]->hours);
        $this->assertSame('Ada Lovelace', $show->timeEntries[1]->userName);

        $this->assertSame(['b2', 'a1'], [
            $show->changesets[0]->revision,
            $show->changesets[1]->revision,
        ]);
        $this->assertSame('Second fix', $show->changesets[0]->comments);
        $this->assertSame('2026-10-04 11:30:00', $show->changesets[0]->committedOn);
        $this->assertSame('dev@example', $show->changesets[0]->committer);
        $this->assertSame('dev@example', $show->changesets[0]->authorName);
        $this->assertSame('main', $show->changesets[0]->repositoryIdentifier);
        $this->assertSame('Ada Lovelace', $show->changesets[1]->authorName);
        $this->assertSame('First fix', $show->changesets[1]->comments);

        $ownShow = $history->present($own, $issue->fresh());
        $this->assertContains(IssueHistoryPresenter::TAB_SPENT_TIME, $ownShow->historyTabLabels);
        $this->assertContains(IssueHistoryPresenter::TAB_REVISIONS, $ownShow->historyTabLabels);
        $this->assertCount(1, $ownShow->timeEntries);
        $this->assertSame('Ada Lovelace', $ownShow->timeEntries[0]->userName);
        $this->assertSame(1.5, $ownShow->timeEntries[0]->hours);

        $hidden = $history->present($blocked, $issue->fresh());
        $this->assertNotContains(IssueHistoryPresenter::TAB_SPENT_TIME, $hidden->historyTabLabels);
        $this->assertNotContains(IssueHistoryPresenter::TAB_REVISIONS, $hidden->historyTabLabels);
        $this->assertSame([], $hidden->timeEntries);
        $this->assertSame([], $hidden->changesets);

        $onlyOthers = $issues->create($reader, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Someone else logged time',
            'status_id' => $world->newStatus->id,
        ]);
        $this->logTime($world->project, $onlyOthers, $bea, $reader, 3, '2026-10-02', 'Not Ada');
        $emptyOwn = $history->present($own, $onlyOthers->fresh());
        $this->assertSame([IssueHistoryPresenter::TAB_SPENT_TIME], $emptyOwn->historyTabLabels);
        $this->assertTrue($emptyOwn->historyBlockVisible);
        $this->assertSame([], $emptyOwn->timeEntries);
        $this->assertSame([], $emptyOwn->historyEntries);

        $revisionsOnly = $issues->create($reader, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Revisions only',
            'status_id' => $world->newStatus->id,
        ]);
        $this->linkRevision($world->project, $revisionsOnly, 'c3', 'Linked', '2026-10-05 08:00:00', null);
        $revisions = $history->present($reader, $revisionsOnly->fresh());
        $this->assertSame([IssueHistoryPresenter::TAB_REVISIONS], $revisions->historyTabLabels);
        $this->assertSame('c3', $revisions->changesets[0]->revision);

        $projects->disableModule($world->project, 'time_tracking');
        $projects->disableModule($world->project, 'repository');
        $modulesOff = $history->present($reader, $issue->fresh());
        $this->assertSame(
            [IssueHistoryPresenter::TAB_HISTORY, IssueHistoryPresenter::TAB_NOTES],
            $modulesOff->historyTabLabels,
        );
        $this->assertSame([], $modulesOff->timeEntries);
        $this->assertSame([], $modulesOff->changesets);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function member(DomainFixture $world, User $user, string $name, array $permissions, string $timeVisibility = 'all'): User
    {
        $role = Role::query()->create([
            'name' => $name,
            'builtin' => 0,
            'assignable' => true,
            'permissions' => $permissions,
            'issues_visibility' => 'default',
            'time_entries_visibility' => $timeVisibility,
        ]);
        app(MembershipService::class)->assignRole($world->project, $user, $role);

        return $user;
    }

    private function storeJournal(int $issueId, int $userId, ?string $notes): Journal
    {
        $journal = Journal::query()->create([
            'journalized_id' => $issueId,
            'journalized_type' => 'Issue',
            'user_id' => $userId,
            'notes' => $notes,
            'private_notes' => false,
            'created_on' => now(),
        ]);
        $this->assertInstanceOf(Journal::class, $journal);

        return $journal;
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value, 'updated_on' => now()],
        );
    }

    private function logTime(
        Project $project,
        Issue $issue,
        User $user,
        User $author,
        float $hours,
        string $spentOn,
        string $comments,
    ): void {
        $activity = Enumeration::query()->firstOrCreate(
            ['type' => 'TimeEntryActivity', 'name' => 'Development'],
            ['active' => true, 'is_default' => true, 'position' => 1],
        );
        $date = Carbon::parse($spentOn);
        TimeEntry::query()->create([
            'activity_id' => $activity->id,
            'author_id' => $author->id,
            'comments' => $comments,
            'created_on' => $date->copy()->setTime(12, 0),
            'hours' => $hours,
            'issue_id' => $issue->id,
            'project_id' => $project->id,
            'spent_on' => $spentOn,
            'tmonth' => (int) $date->format('n'),
            'tweek' => (int) $date->format('W'),
            'tyear' => (int) $date->format('Y'),
            'updated_on' => $date->copy()->setTime(12, 0),
            'user_id' => $user->id,
        ]);
    }

    private function linkRevision(
        Project $project,
        Issue $issue,
        string $revision,
        string $comments,
        string $committedOn,
        ?User $user,
    ): void {
        $repository = Repository::query()->firstOrCreate(
            ['project_id' => $project->id, 'identifier' => 'main'],
            [
                'url' => '/repos/main',
                'type' => 'Repository::Git',
                'is_default' => true,
                'created_on' => now(),
            ],
        );
        $changeset = Changeset::query()->create([
            'repository_id' => $repository->id,
            'revision' => $revision,
            'comments' => $comments,
            'committed_on' => $committedOn,
            'committer' => 'dev@example',
            'user_id' => $user?->id,
            'scmid' => $revision,
        ]);
        DB::table('changesets_issues')->insert([
            'changeset_id' => $changeset->id,
            'issue_id' => $issue->id,
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function unlinkAll(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function zipEntries(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'assert-zip');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path) === true);
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $this->assertIsString($name);
                $bytes = $zip->getFromIndex($index);
                $this->assertIsString($bytes);
                $entries[$name] = $bytes;
            }

            return $entries;
        } finally {
            $zip->close();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  list<JournalEntryView>  $entries
     * @return list<int>
     */
    private function ids(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->journalId;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function keys(JournalEntryView $entry): array
    {
        $keys = [];
        foreach ($entry->actions as $action) {
            $keys[] = $action->key;
        }

        return $keys;
    }

    /**
     * @return list<JournalMenuItemView>
     */
    private function menu(JournalEntryView $entry): array
    {
        foreach ($entry->actions as $action) {
            if ($action->key === 'more') {
                return $action->menuItems;
            }
        }

        $this->fail('Missing more control');
    }

    /**
     * @return list<string>
     */
    private function menuLabels(JournalEntryView $entry): array
    {
        $labels = [];
        foreach ($this->menu($entry) as $item) {
            $labels[] = $item->label;
        }

        return $labels;
    }
}
