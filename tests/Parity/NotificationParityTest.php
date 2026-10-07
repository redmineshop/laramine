<?php

namespace Tests\Parity;

use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\Auth\AccountAdminService;
use App\Domain\Auth\AccountRecovery;
use App\Domain\Auth\RegistrationService;
use App\Domain\Issues\IssueRelationService;
use App\Domain\Issues\IssueService;
use App\Domain\Issues\JournalNoteService;
use App\Domain\Notifications\IssueNotifier;
use App\Domain\Notifications\JournalEventClassifier;
use App\Domain\Notifications\NotifiedEventCatalog;
use App\Domain\Notifications\NotifiedEventSetting;
use App\Domain\Settings\SettingValue;
use App\Mail\RedmineNotificationMail;
use App\Models\Attachment;
use App\Models\EmailAddress;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Member;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use App\Models\UserPreference;
use App\Models\Watcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares outbound notification recipients, subjects, and headers to the pin.
 *
 * News, documents, files, messages, and wiki events are named and not sent.
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

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — outbound mail \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/NotificationParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/notifications/mail.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Notifications for news, documents, files, messages, and wiki \| NOT VERIFIED \|/m', $checklist);
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
