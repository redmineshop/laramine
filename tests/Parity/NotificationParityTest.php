<?php

namespace Tests\Parity;

use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\Auth\AccountAdminService;
use App\Domain\Auth\AccountRecovery;
use App\Domain\Auth\RegistrationService;
use App\Domain\Boards\BoardService;
use App\Domain\Boards\MessageService;
use App\Domain\Documents\DocumentService;
use App\Domain\Issues\IssueRelationService;
use App\Domain\Issues\IssueService;
use App\Domain\Issues\JournalNoteService;
use App\Domain\News\NewsService;
use App\Domain\Notifications\IssueNotifier;
use App\Domain\Notifications\JournalEventClassifier;
use App\Domain\Notifications\NotifiedEventCatalog;
use App\Domain\Notifications\NotifiedEventSetting;
use App\Domain\Projects\ProjectService;
use App\Domain\Settings\SettingValue;
use App\Domain\Wiki\WikiService;
use App\Mail\RedmineNotificationMail;
use App\Models\Attachment;
use App\Models\EmailAddress;
use App\Models\EnabledModule;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Watcher;
use App\Models\WikiContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares outbound notification recipients, subjects, and headers to the pin.
 *
 * News, document, file, message, and wiki events are sent.
 */
class NotificationParityTest extends TestCase
{
    use RefreshDatabase;

    private const STAMP = '20261007120000';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 12:00:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_issue_add_matches_preference_rules_and_headers(): void
    {
        $this->bootPin();
        $expected = $this->section('issue_add');
        $this->address($this->user('bea'), 'bea@parity.test');
        $this->address($this->user('finn'), 'finn@parity.test');
        $this->address($this->user('erin'), 'erin@parity.test');
        $this->address($this->user('dora'), 'dora@parity.test');
        $this->preference($this->user('bea'), 'only_assigned');
        $this->preference($this->user('finn'), 'only_owner');
        $this->preference($this->user('erin'), 'selected');
        $this->preference($this->user('dora'), 'none');
        Member::query()->whereKey(7)->update(['mail_notification' => true]);

        $issue = app(IssueService::class)->create($this->user('bea'), $this->project('parity-core'), [
            'tracker_id' => 1,
            'subject' => 'Mail pin',
            'assigned_to_id' => $this->user('ada')->id,
        ]);

        $this->assertMails($expected, ['issue' => (string) $issue->id, 'stamp' => self::STAMP]);
    }

    public function test_no_self_selected_watcher_and_group_checkbox(): void
    {
        $this->bootPin();
        $this->address($this->user('bea'), 'bea@parity.test');
        $this->address($this->user('erin'), 'erin@parity.test');
        $this->address($this->user('finn'), 'finn@parity.test');
        $this->preference($this->user('admin'), 'all', true);
        $this->preference($this->user('erin'), 'selected');
        $this->preference($this->user('bea'), 'only_assigned');
        Member::query()->whereKey(7)->update(['mail_notification' => true]);

        $issue = app(IssueService::class)->create($this->user('admin'), $this->project('parity-core'), [
            'tracker_id' => 1,
            'subject' => 'Self pin',
            'assigned_to_id' => $this->user('admin')->id,
        ]);
        $this->assertMails($this->section('no_self'), ['issue' => (string) $issue->id, 'stamp' => self::STAMP]);

        Mail::fake();
        Watcher::query()->create([
            'watchable_type' => 'Issue',
            'watchable_id' => 1,
            'user_id' => $this->user('bea')->id,
        ]);
        $this->preference($this->user('admin'), 'all', true);
        app(IssueService::class)->update($this->user('admin'), $this->issue(1), ['notes' => 'Watcher pin']);
        $this->assertMails($this->section('watcher'), []);

        Mail::fake();
        Member::query()->whereKey(7)->update(['mail_notification' => false]);
        app(IssueService::class)->update($this->user('admin'), $this->issue(1), ['notes' => 'Selected off']);
        $this->assertMails($this->section('selected_off'), []);

        Mail::fake();
        $this->preference($this->user('ada'), 'only_owner');
        $this->preference($this->user('bea'), 'only_owner');
        $this->preference($this->user('admin'), 'all', true);
        Member::query()->whereKey(4)->update(['mail_notification' => true]);
        app(IssueService::class)->update($this->user('admin'), $this->issue(3), ['notes' => 'Group on']);
        $this->assertMails($this->section('group_on'), []);

        Mail::fake();
        Member::query()->whereKey(4)->update(['mail_notification' => false]);
        app(IssueService::class)->update($this->user('admin'), $this->issue(3), ['notes' => 'Group off']);
        $this->assertMails($this->section('group_off'), []);
    }

    public function test_private_notes_reach_only_view_private_notes(): void
    {
        $this->bootPin();
        $this->address($this->user('bea'), 'bea@parity.test');
        $this->address($this->user('erin'), 'erin@parity.test');
        $this->preference($this->user('admin'), 'all', true);
        $this->preference($this->user('bea'), 'only_my_events');
        $this->preference($this->user('erin'), 'selected');
        Member::query()->whereKey(7)->update(['mail_notification' => true]);
        Watcher::query()->create([
            'watchable_type' => 'Issue',
            'watchable_id' => 1,
            'user_id' => $this->user('bea')->id,
        ]);

        app(IssueService::class)->update($this->user('admin'), $this->issue(1), [
            'notes' => 'Secret pin note',
            'private_notes' => true,
        ]);
        $this->assertMails($this->section('private_note'), []);

        Mail::fake();
        $this->preference($this->user('bea'), 'all');
        app(IssueService::class)->update($this->user('admin'), $this->issue(1), [
            'status_id' => 2,
            'notes' => 'Secret status note',
            'private_notes' => true,
        ]);
        $case = $this->section('private_and_status');
        $note = $this->stringField($case, 'note');
        $mails = $this->queued();
        $byTo = [];
        foreach ($mails as $mail) {
            $byTo[$mail['to']] = $mail;
        }
        foreach ($this->stringList($case, 'with_note') as $address) {
            $this->assertArrayHasKey($address, $byTo);
            $this->assertSame($this->stringField($case, 'subject'), $byTo[$address]['subject']);
            $this->assertStringContainsString($note, $byTo[$address]['body']);
        }
        foreach ($this->stringList($case, 'without_note') as $address) {
            $this->assertArrayHasKey($address, $byTo);
            $this->assertSame($this->stringField($case, 'subject'), $byTo[$address]['subject']);
            $this->assertStringNotContainsString($note, $byTo[$address]['body']);
        }
        foreach ($this->stringList($case, 'absent') as $address) {
            $this->assertArrayNotHasKey($address, $byTo);
        }
    }

    public function test_notified_events_gate_issue_mail_and_leave_unbuilt_modules_quiet(): void
    {
        $this->bootPin();
        $expected = $this->expectation();
        $this->assertSame($expected['unbuilt'], NotifiedEventCatalog::unbuilt());
        foreach (NotifiedEventCatalog::unbuilt() as $name) {
            $this->assertFalse(NotifiedEventCatalog::built($name));
        }
        foreach ($expected['defaults_omit'] as $name) {
            $this->assertNotContains($name, NotifiedEventCatalog::defaults());
            $this->assertTrue(NotifiedEventCatalog::built($name));
        }

        $classifier = app(JournalEventClassifier::class);
        foreach ($expected['classifier'] as $row) {
            $this->assertIsArray($row);
            $journal = $this->journal($this->intField($row, 'journal_id'));
            $journal->load('details');
            $this->assertSame($row['specific'], $classifier->specific($journal));
            foreach ($row['checks'] as $check) {
                $this->assertIsArray($check);
                $enabled = $check['enabled'];
                $this->assertIsArray($enabled);
                /** @var list<string> $enabled */
                $this->assertSame($check['emits'], $classifier->emits($journal, $enabled));
            }
        }

        $this->setting(SettingValue::NOTIFIED_EVENTS, '["issue_status_updated"]');
        app(IssueService::class)->update($this->user('admin'), $this->issue(2), ['subject' => 'No status mail']);
        $this->assertCount($this->intField($this->section('events_off'), 'subject_only_count'), $this->queued());

        Mail::fake();
        app(IssueService::class)->update($this->user('admin'), $this->issue(2), ['status_id' => 2]);
        $tos = array_map(fn (array $mail): string => $mail['to'], $this->queued());
        sort($tos);
        $statusOnly = $this->section('events_off')['status_only'];
        $this->assertIsArray($statusOnly);
        $this->assertSame($statusOnly['to'], $tos);

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["issue_fixed_version_updated"]');
        $journal = $this->journal(5);
        app(IssueNotifier::class)->edited($this->user('admin'), $this->issue(1), $journal, 1);
        $fixed = $this->section('fixed_version_only');
        $mails = $this->queued();
        $this->assertSame($fixed['to'], array_map(fn (array $mail): string => $mail['to'], $mails));
        $this->assertSame($this->stringField($fixed, 'message_id'), $mails[0]['message_id']);
        $this->assertSame($fixed['references'], $mails[0]['references']);
        foreach ($fixed['headers'] as $name => $value) {
            $this->assertSame($value, $mails[0]['headers'][$name] ?? null);
        }

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["news_added"]');
        app(IssueNotifier::class)->edited($this->user('admin'), $this->issue(1), $journal, 1);
        $this->assertCount($this->intField($expected, 'news_only_count'), $this->queued());
        $this->assertTrue(app(NotifiedEventSetting::class)->allows(NotifiedEventCatalog::NEWS_ADDED));
        $this->assertFalse(app(NotifiedEventSetting::class)->allows(NotifiedEventCatalog::ISSUE_ADDED));

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["issue_updated"]');
        app(IssueService::class)->update($this->user('admin'), $this->issue(2), [
            'subject' => 'Quiet pin',
            'notify' => false,
        ]);
        $this->assertCount($this->intField($expected, 'notify_false_count'), $this->queued());
    }

    public function test_attachment_claim_reuses_the_container_journal(): void
    {
        $this->bootPin();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["issue_attachment_added"]');
        $ada = $this->user('ada');
        $container = app(AttachmentContainerService::class);
        $uploaded = $container->upload($ada, 'pin.txt', 'pin-bytes', 'text/plain');
        $before = (int) Journal::query()->max('id');
        $attachment = $container->claim($ada, $uploaded['token'], 1, null, null, null);
        $this->assertInstanceOf(Attachment::class, $attachment);
        $journals = Journal::query()->where('id', '>', $before)->orderBy('id')->get();
        $this->assertCount(1, $journals);
        $journal = $journals->first();
        $this->assertInstanceOf(Journal::class, $journal);
        $detail = JournalDetail::query()->where('journal_id', $journal->id)->first();
        $this->assertInstanceOf(JournalDetail::class, $detail);
        $this->assertSame('attachment', (string) $detail->property);
        $this->assertSame((string) $attachment->id, (string) $detail->prop_key);
        $this->assertSame('pin.txt', (string) $detail->value);
        $this->assertMails($this->section('attachment_claim'), ['journal' => (string) $journal->id, 'stamp' => self::STAMP]);

        Mail::fake();
        $container->delete($ada, $attachment);
        $removal = $this->section('attachment_claim');
        $this->assertCount($this->intField($removal, 'removal_count'), $this->queued());
    }

    public function test_quote_and_relation_add_queue_issue_edit_mail(): void
    {
        $this->bootPin();
        $this->preference($this->user('admin'), 'all', true);
        $before = (int) Journal::query()->max('id');
        app(JournalNoteService::class)->quote($this->user('admin'), $this->journal(1));
        $journalId = (int) Journal::query()->where('id', '>', $before)->max('id');
        $this->assertMails($this->section('quote'), ['journal' => (string) $journalId, 'stamp' => self::STAMP]);

        Mail::fake();
        $this->preference($this->user('admin'), 'all', false);
        $before = (int) Journal::query()->max('id');
        app(IssueRelationService::class)->add($this->user('admin'), $this->issue(2), $this->issue(3), 'relates');
        $journalId = (int) Journal::query()->where('id', '>', $before)->max('id');
        $this->assertMails($this->section('relation'), ['journal' => (string) $journalId, 'stamp' => self::STAMP]);
    }

    public function test_account_registration_recovery_and_lock_mail(): void
    {
        $this->bootPin();
        $accounts = $this->section('accounts');
        $registration = app(RegistrationService::class);
        $admin = app(AccountAdminService::class);
        $recovery = app(AccountRecovery::class);

        $this->setting(SettingValue::SELF_REGISTRATION, '1');
        $registered = $registration->register($this->signup('nora', 'nora@parity.test'));
        $token = $registered->token;
        $this->assertInstanceOf(Token::class, $token);
        $activation = $accounts['activation'];
        $this->assertIsArray($activation);
        $this->assertMails($activation, [
            'user' => (string) $registered->user->id,
            'token' => (string) $token->id,
            'stamp' => self::STAMP,
        ]);
        $this->assertStringContainsString((string) $token->value, $this->queued()[0]['body']);

        Mail::fake();
        $this->setting(SettingValue::SELF_REGISTRATION, '2');
        $manual = $registration->register($this->signup('mira', 'mira@parity.test'));
        $this->assertNull($manual->token);
        $request = $accounts['activation_request'];
        $this->assertIsArray($request);
        $this->assertMails($request, ['stamp' => self::STAMP]);

        Mail::fake();
        $this->setting(SettingValue::SELF_REGISTRATION, '3');
        $registration->register($this->signup('nils', 'nils@parity.test'));
        $this->assertCount($this->intField($accounts, 'automatic_count'), $this->queued());

        Mail::fake();
        $this->assertTrue($recovery->request('ada@parity.test'));
        $recoveryToken = Token::query()->where('user_id', $this->user('ada')->id)->where('action', Token::ACTION_RECOVERY)->first();
        $this->assertInstanceOf(Token::class, $recoveryToken);
        $lost = $accounts['lost_password'];
        $this->assertIsArray($lost);
        $this->assertMails($lost, ['token' => (string) $recoveryToken->id, 'stamp' => self::STAMP]);
        $this->assertStringContainsString((string) $recoveryToken->value, $this->queued()[0]['body']);

        Mail::fake();
        $created = $admin->create($this->user('admin'), [
            'login' => 'otto',
            'firstname' => 'Otto',
            'lastname' => 'Pin',
            'mail' => 'otto@parity.test',
            'password' => 'parity-pass',
            'password_confirmation' => 'parity-pass',
        ]);
        $information = $accounts['information'];
        $this->assertIsArray($information);
        $this->assertMails($information, ['user' => (string) $created->id, 'stamp' => self::STAMP]);

        Mail::fake();
        $admin->activate($this->user('admin'), $manual->user->fresh() ?? $manual->user);
        $activated = $accounts['activated'];
        $this->assertIsArray($activated);
        $this->assertMails($activated, ['stamp' => self::STAMP]);

        Mail::fake();
        $this->address($this->user('erin'), 'erin@parity.test');
        $admin->lock($this->user('admin'), $this->user('erin'));
        $locked = $accounts['locked'];
        $this->assertIsArray($locked);
        $this->assertMails($locked, ['stamp' => self::STAMP]);

        Mail::fake();
        $admin->unlock($this->user('admin'), $this->user('erin')->fresh() ?? $this->user('erin'));
        $unlocked = $accounts['unlocked'];
        $this->assertIsArray($unlocked);
        $this->assertMails($unlocked, ['stamp' => self::STAMP]);
    }

    public function test_news_document_and_file_mail_matches_the_pin(): void
    {
        $this->bootPin();
        $modules = $this->section('modules');
        $project = $this->project('parity-core');
        $projects = app(ProjectService::class);
        $projects->enableModule($project, 'news');
        $projects->enableModule($project, 'documents');
        $projects->enableModule($project, 'files');
        $this->address($this->user('bea'), 'bea@parity.test');
        $this->preference($this->user('bea'), 'all');
        $this->grant(1, ['view_news']);
        $this->grant(2, ['comment_news', 'view_documents', 'add_documents', 'edit_documents', 'view_files', 'manage_files']);

        $admin = $this->user('admin');
        $bea = $this->user('bea');
        $quiet = app(NewsService::class)->create($admin, $project, 'Quiet', null, null);
        $this->assertMails($this->moduleCase($modules, 'without_view'), [
            'news' => (string) $quiet->id,
            'stamp' => self::STAMP,
        ]);
        $this->assertNoIssueHeader();

        $this->grant(2, ['view_news']);
        Mail::fake();
        $news = app(NewsService::class)->create($admin, $project, 'Pin release', null, null);
        $vars = ['news' => (string) $news->id, 'stamp' => self::STAMP];
        $this->assertMails($this->moduleCase($modules, 'news_added'), $vars);
        $this->assertNoIssueHeader();

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["file_added"]');
        app(NewsService::class)->create($admin, $project, 'Silent', null, null);
        $this->assertCount($this->intField($modules, 'events_off_count'), $this->queued());

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["news_comment_added"]');
        app(NewsService::class)->watch($this->user('ada'), $news);
        $comment = app(NewsService::class)->addComment($bea, $news, 'Noted');
        $this->assertMails($this->moduleCase($modules, 'comment'), [
            'news' => (string) $news->id,
            'comment' => (string) $comment->id,
            'stamp' => self::STAMP,
        ]);
        $this->assertNoIssueHeader();

        Mail::fake();
        $this->preference($admin, 'all', true);
        $again = app(NewsService::class)->addComment($admin, $news, 'Again');
        $this->assertMails($this->moduleCase($modules, 'comment_no_self'), [
            'news' => (string) $news->id,
            'comment' => (string) $again->id,
            'stamp' => self::STAMP,
        ]);
        $this->preference($admin, 'all', false);

        Mail::fake();
        $projects->disableModule($project, 'news');
        app(NewsService::class)->create($admin, $project->fresh() ?? $project, 'Offline', null, null);
        $this->assertCount($this->intField($modules, 'module_off_count'), $this->queued());

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["document_added"]');
        $document = app(DocumentService::class)->create($admin, $project, 'Guide', 4, null);
        $this->assertMails($this->moduleCase($modules, 'document_added'), [
            'document' => (string) $document->id,
            'stamp' => self::STAMP,
        ]);
        $this->assertNoIssueHeader();

        Mail::fake();
        $files = app(AttachmentContainerService::class);
        $guide = $files->upload($bea, 'guide.txt', 'guide', 'text/plain');
        $attachment = $files->claim($bea, $guide['token'], null, null, 'guide.txt', null, (int) $document->id);
        $this->assertMails($this->moduleCase($modules, 'document_file'), [
            'document' => (string) $document->id,
            'attachment' => (string) $attachment->id,
            'stamp' => self::STAMP,
        ]);

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["file_added"]');
        $againFile = $files->upload($bea, 'again.txt', 'again', 'text/plain');
        $files->claim($bea, $againFile['token'], null, null, 'again.txt', null, (int) $document->id);
        $this->assertCount($this->intField($modules, 'document_file_not_file_added_count'), $this->queued());

        Mail::fake();
        $tree = $files->upload($admin, 'tree.txt', 'tree', 'text/plain');
        $projectFile = $files->claim($admin, $tree['token'], null, null, 'tree.txt', null, null, (int) $project->id);
        $this->assertMails($this->moduleCase($modules, 'file_added'), [
            'attachment' => (string) $projectFile->id,
            'stamp' => self::STAMP,
        ]);
        $this->assertNoIssueHeader();

        Mail::fake();
        $issueFile = $files->upload($this->user('ada'), 'issue.txt', 'issue', 'text/plain');
        $files->claim($this->user('ada'), $issueFile['token'], 1, null, 'issue.txt', null);
        $this->assertCount($this->intField($modules, 'issue_claim_count'), $this->queued());
    }

    public function test_wiki_and_message_mail_match_the_pin(): void
    {
        $this->bootPin();
        $this->enableModule('wiki');
        $this->enableModule('boards');
        $this->grant(1, ['view_wiki_pages', 'edit_wiki_pages', 'view_messages', 'add_messages']);
        $this->grant(2, [
            'view_wiki_pages',
            'edit_wiki_pages',
            'rename_wiki_pages',
            'view_messages',
            'add_messages',
            'edit_messages',
            'manage_boards',
            'add_wiki_page_watchers',
        ]);
        $this->address($this->user('bea'), 'bea@parity.test');
        $expected = $this->moduleExpectation();
        $project = Project::query()->findOrFail(1);
        $ada = $this->user('ada');
        $wiki = app(WikiService::class);
        $page = $wiki->createPage($ada, $project, 'cook_book', "h1. Raw heading\nalpha\n", 'first');
        $content = WikiContent::query()->where('page_id', $page->id)->first();
        $this->assertInstanceOf(WikiContent::class, $content);
        $vars = [
            'page' => (string) $page->id,
            'content' => (string) $content->id,
            'stamp' => self::STAMP,
        ];
        $this->assertMails($this->moduleCase($expected, 'wiki_added'), $vars);

        Mail::fake();
        Carbon::setTestNow('2026-10-07 13:00:00');
        $wiki->updateContent($ada, $page, "h1. Raw heading\ngamma\n", 'second');
        $vars['stamp'] = '20261007130000';
        $this->assertMails($this->moduleCase($expected, 'wiki_updated'), $vars);

        Mail::fake();
        $this->setting(SettingValue::NOTIFIED_EVENTS, '["issue_added"]');
        Carbon::setTestNow('2026-10-07 13:10:00');
        $wiki->updateContent($ada, $page, "h1. Raw heading\nquiet\n", 'third');
        $this->assertCount(0, $this->queued());

        Setting::query()->where('name', SettingValue::NOTIFIED_EVENTS)->delete();
        $this->preference($ada, 'only_my_events', true);
        Mail::fake();
        Carbon::setTestNow('2026-10-07 13:20:00');
        $wiki->updateContent($ada, $page, "h1. Raw heading\ndelta\n", 'fourth');
        $vars['stamp'] = '20261007132000';
        $this->assertMails($this->moduleCase($expected, 'wiki_no_self'), $vars);

        $this->preference($ada, 'only_my_events', false);
        $wiki->addWatcher($this->user('admin'), $page, $this->user('bea'));
        Mail::fake();
        Carbon::setTestNow('2026-10-07 13:30:00');
        $wiki->updateContent($ada, $page, "h1. Raw heading\nepsilon\n", 'fifth');
        $vars['stamp'] = '20261007133000';
        $this->assertMails($this->moduleCase($expected, 'wiki_watcher'), $vars);

        Mail::fake();
        $wiki->rename($this->user('bea'), $page->fresh() ?? $page, 'Pantry');
        $this->assertCount(0, $this->queued());

        $board = app(BoardService::class)->create($this->user('bea'), $project, 'General', null, null, null);
        Mail::fake();
        Carbon::setTestNow('2026-10-07 12:00:00');
        $topic = app(MessageService::class)->postTopic($ada, $board, 'Hello', 'topic body');
        $topicVars = [
            'message' => (string) $topic->id,
            'topic' => (string) $topic->id,
            'stamp' => self::STAMP,
        ];
        $this->assertMails($this->moduleCase($expected, 'message_topic'), $topicVars);

        Mail::fake();
        Carbon::setTestNow('2026-10-07 12:30:00');
        $reply = app(MessageService::class)->reply($this->user('bea'), $topic, null, 'reply body');
        $this->assertMails($this->moduleCase($expected, 'message_reply'), [
            'message' => (string) $reply->id,
            'topic' => (string) $topic->id,
            'stamp' => self::STAMP,
        ]);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — outbound mail \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/NotificationParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/notifications/mail.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Notifications for news, documents, and files \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Notifications for messages and wiki \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/notifications/modules.json', $checklist);
    }

    private function assertNoIssueHeader(): void
    {
        $mails = $this->queued();
        $this->assertNotEmpty($mails);
        foreach ($mails as $mail) {
            $this->assertArrayNotHasKey('X-Redmine-Issue-Id', $mail['headers']);
        }
    }

    private function bootPin(): void
    {
        Redmine701Fixture::load();
        $this->setting(SettingValue::HOST_NAME, 'parity.test');
        $this->setting(SettingValue::MAIL_FROM, 'laramine@parity.test');
        $this->setting(SettingValue::APP_TITLE, 'Parity');
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, string>  $vars
     */
    private function assertMails(array $expected, array $vars): void
    {
        $mails = $this->queued();
        $rows = $expected['mails'] ?? null;
        if (! is_array($rows)) {
            $rows = [$expected];
        }
        $this->assertCount(count($rows), $mails, json_encode(array_column($mails, 'to')) ?: '');
        foreach ($rows as $index => $row) {
            $this->assertIsArray($row);
            $actual = $mails[$index];
            $this->assertSame($this->fill($this->stringField($row, 'to'), $vars), $actual['to']);
            if (isset($row['subject'])) {
                $this->assertSame($this->fill($this->stringField($row, 'subject'), $vars), $actual['subject']);
            }
            if (isset($row['message_id'])) {
                $this->assertSame($this->fill($this->stringField($row, 'message_id'), $vars), $actual['message_id']);
            }
            if (isset($row['references'])) {
                $references = $row['references'];
                $this->assertIsArray($references);
                $filled = [];
                foreach ($references as $reference) {
                    $this->assertIsString($reference);
                    $filled[] = $this->fill($reference, $vars);
                }
                $this->assertSame($filled, $actual['references']);
            }
            if (isset($expected['headers']) && is_array($expected['headers'])) {
                foreach ($expected['headers'] as $name => $value) {
                    $this->assertIsString($name);
                    $this->assertIsString($value);
                    $this->assertSame($this->fill($value, $vars), $actual['headers'][$name] ?? null);
                }
            }
            if (isset($row['headers']) && is_array($row['headers'])) {
                foreach ($row['headers'] as $name => $value) {
                    $this->assertIsString($name);
                    $this->assertIsString($value);
                    $this->assertSame($this->fill($value, $vars), $actual['headers'][$name] ?? null);
                }
            }
            foreach ($this->optionalStrings($row, 'body_contains') as $needle) {
                $this->assertStringContainsString($this->fill($needle, $vars), $actual['body']);
            }
            foreach ($this->optionalStrings($row, 'body_omits') as $needle) {
                $this->assertStringNotContainsString($this->fill($needle, $vars), $actual['body']);
            }
            $this->assertSame('laramine@parity.test', $actual['from']);
        }
        foreach ($this->optionalStrings($expected, 'absent') as $address) {
            foreach ($mails as $mail) {
                $this->assertNotSame($address, $mail['to']);
            }
        }
    }

    /**
     * @return list<array{to: string, subject: string, message_id: string, references: list<string>, headers: array<string, string>, body: string, from: string}>
     */
    private function queued(): array
    {
        $queued = Mail::queued(RedmineNotificationMail::class);
        $rows = [];
        foreach ($queued as $mail) {
            $this->assertInstanceOf(RedmineNotificationMail::class, $mail);
            $headers = $mail->headers()->text;
            $references = $mail->headers()->references;
            $rows[] = [
                'to' => $mail->envelope()->to[0]->address ?? $mail->recipient,
                'subject' => (string) $mail->envelope()->subject,
                'message_id' => (string) $mail->headers()->messageId,
                'references' => array_values($references),
                'headers' => $headers,
                'body' => $mail->bodyText,
                'from' => $mail->envelope()->from->address ?? '',
            ];
        }
        usort($rows, fn (array $left, array $right): int => [$left['to'], $left['subject']] <=> [$right['to'], $right['subject']]);

        return $rows;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function fill(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace('{'.$key.'}', $value, $out);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleExpectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/notifications/modules.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function moduleCase(array $expected, string $key): array
    {
        $case = $expected[$key] ?? null;
        $this->assertIsArray($case);

        return $case;
    }

    private function enableModule(string $name): void
    {
        EnabledModule::query()->create(['project_id' => 1, 'name' => $name]);
    }

    /**
     * @param  list<string>  $names
     */
    private function grant(int $roleId, array $names): void
    {
        $role = Role::query()->findOrFail($roleId);
        $permissions = $role->permissions;
        $this->assertIsArray($permissions);
        $role->permissions = array_values(array_unique([...$permissions, ...$names]));
        $role->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/notifications/mail.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->expectation()[$key] ?? null;
        $this->assertIsArray($section);

        return $section;
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(['name' => $name], ['value' => $value]);
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function project(string $identifier): Project
    {
        $project = Project::query()->where('identifier', $identifier)->first();
        $this->assertInstanceOf(Project::class, $project);

        return $project;
    }

    private function issue(int $id): Issue
    {
        $issue = Issue::query()->find($id);
        $this->assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    private function journal(int $id): Journal
    {
        $journal = Journal::query()->find($id);
        $this->assertInstanceOf(Journal::class, $journal);

        return $journal;
    }

    private function preference(User $user, string $notification, ?bool $noSelf = null): void
    {
        $user->forceFill(['mail_notification' => $notification])->save();
        if ($noSelf === null) {
            return;
        }
        UserPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['hide_mail' => false, 'others' => json_encode(['no_self_notified' => $noSelf])],
        );
    }

    private function address(User $user, string $address): void
    {
        EmailAddress::query()->updateOrCreate(
            ['user_id' => $user->id, 'address' => $address],
            ['is_default' => true, 'notify' => true],
        );
    }

    /**
     * @return array{login: string, password: string, password_confirmation: string, firstname: string, lastname: string, mail: string}
     */
    private function signup(string $login, string $mail): array
    {
        return [
            'login' => $login,
            'password' => 'parity-pass',
            'password_confirmation' => 'parity-pass',
            'firstname' => 'Pin',
            'lastname' => 'Account',
            'mail' => $mail,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->assertIsInt($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function stringList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value);
        $strings = [];
        foreach ($value as $item) {
            $this->assertIsString($item);
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function optionalStrings(array $row, string $key): array
    {
        if (! array_key_exists($key, $row)) {
            return [];
        }

        return $this->stringList($row, $key);
    }
}
