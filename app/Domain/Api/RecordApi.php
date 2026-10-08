<?php

namespace App\Domain\Api;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\ModuleGate;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Domain\Attachments\AttachmentContainerService;
use App\Domain\Boards\BoardService;
use App\Domain\Boards\MessageService;
use App\Domain\Files\ProjectFileService;
use App\Domain\Issues\IssueRelationService;
use App\Domain\Issues\JournalNoteService;
use App\Domain\News\NewsService;
use App\Domain\PermissionDeniedException;
use App\Domain\TimeEntries\TimeEntryService;
use App\Domain\Wiki\WikiService;
use App\Http\Api\ApiCall;
use App\Http\Api\ApiPage;
use App\Http\Api\ApiQuery;
use App\Http\Api\ApiResult;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Document;
use App\Models\Issue;
use App\Models\IssueRelation;
use App\Models\IssueStatus;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Version;
use App\Models\WikiContent;
use App\Models\WikiPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Time entries, relations, news, wiki, boards, files, attachments, search, and journals.
 */
final class RecordApi
{
    public function __construct(
        private readonly ApiCall $calls,
        private readonly ApiValues $values,
        private readonly PermissionService $permissions,
        private readonly ModuleGate $modules,
        private readonly IssueVisibility $issues,
        private readonly TimeEntryVisibility $timeVisibility,
        private readonly TimeEntryService $timeEntries,
        private readonly IssueRelationService $relations,
        private readonly NewsService $news,
        private readonly WikiService $wiki,
        private readonly BoardService $boards,
        private readonly MessageService $messages,
        private readonly ProjectFileService $files,
        private readonly AttachmentContainerService $attachments,
        private readonly JournalNoteService $notes,
    ) {}

    public function timeEntries(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $page = ApiPage::from($request);
            $entries = $this->visibleTimeEntries($actor, $request);
            $slice = $page->slice($entries);
            $rows = [];
            foreach ($slice['rows'] as $entry) {
                $rows[] = $this->timeDocument($actor, $entry);
            }

            return ApiResult::ok($this->calls->collection('time_entries', $rows, $page, $slice['total']));
        });
    }

    public function showTimeEntry(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $entry = $this->readableTimeEntry($actor, $id);
            if ($entry instanceof ApiResult) {
                return $entry;
            }

            return ApiResult::ok(['time_entry' => $this->timeDocument($actor, $entry)]);
        });
    }

    public function storeTimeEntry(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $attributes = ApiQuery::resource($request, 'time_entry');
            $project = $this->timeProject($actor, $attributes);
            if ($project instanceof ApiResult) {
                return $project;
            }
            if (! $project->isModuleEnabled('time_tracking')) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $entry = $this->timeEntries->create($actor, $project, $attributes);

            return ApiResult::created(['time_entry' => $this->timeDocument($actor, $entry)]);
        });
    }

    public function updateTimeEntry(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $entry = $this->readableTimeEntry($actor, $id);
            if ($entry instanceof ApiResult) {
                return $entry;
            }
            $this->timeEntries->update($actor, $entry, ApiQuery::resource($request, 'time_entry'));

            return ApiResult::noContent();
        });
    }

    public function destroyTimeEntry(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $entry = $this->readableTimeEntry($actor, $id);
            if ($entry instanceof ApiResult) {
                return $entry;
            }
            $this->timeEntries->delete($actor, $entry);

            return ApiResult::noContent();
        });
    }

    public function relations(User $actor, int $issueId): ApiResult
    {
        return $this->calls->run(function () use ($actor, $issueId): ApiResult {
            $issue = $this->readableIssue($actor, $issueId);
            if ($issue instanceof ApiResult) {
                return $issue;
            }
            $rows = [];
            $relations = IssueRelation::query()
                ->where('issue_from_id', $issue->id)
                ->orWhere('issue_to_id', $issue->id)
                ->orderBy('id')
                ->get();
            foreach ($relations as $relation) {
                $rows[] = $this->relationDocument($issue, $relation);
            }

            return ApiResult::ok(['relations' => $rows]);
        });
    }

    public function showRelation(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $relation = IssueRelation::query()->find($id);
            if (! $relation instanceof IssueRelation) {
                return ApiResult::fail(404, 'Not found');
            }
            $issue = $this->readableIssue($actor, (int) $relation->issue_from_id);
            if ($issue instanceof ApiResult) {
                return $issue;
            }

            return ApiResult::ok(['relation' => $this->relationDocument($issue, $relation)]);
        });
    }

    public function storeRelation(User $actor, int $issueId, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $issueId, $request): ApiResult {
            $issue = $this->readableIssue($actor, $issueId);
            if ($issue instanceof ApiResult) {
                return $issue;
            }
            $attributes = ApiQuery::resource($request, 'relation');
            if (! is_numeric($attributes['issue_to_id'] ?? null)) {
                return ApiResult::fail(422, 'Related issue is required.');
            }
            $other = Issue::query()->find((int) $attributes['issue_to_id']);
            if (! $other instanceof Issue) {
                return ApiResult::fail(404, 'Not found');
            }
            $type = is_string($attributes['relation_type'] ?? null) ? $attributes['relation_type'] : 'relates';
            $relation = $this->relations->add($actor, $issue, $other, $type);

            return ApiResult::created(['relation' => $this->relationDocument($issue, $relation)]);
        });
    }

    public function destroyRelation(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $relation = IssueRelation::query()->find($id);
            if (! $relation instanceof IssueRelation) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->relations->remove($actor, $relation);

            return ApiResult::noContent();
        });
    }

    public function newsIndex(User $actor, Request $request, ?string $projectKey): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request, $projectKey): ApiResult {
            $page = ApiPage::from($request);
            $project = null;
            if ($projectKey !== null) {
                $project = $this->projectKey($actor, $projectKey);
                if ($project instanceof ApiResult) {
                    return $project;
                }
                $this->modules->allow($actor, $project, 'news', 'view_news');
            }
            $ids = $project instanceof Project
                ? $this->news->visibleIds($actor, $project)
                : $this->newsIdsWithModule($actor);
            $items = $ids === []
                ? []
                : News::query()->whereIn('id', $ids)->orderByDesc('created_on')->orderByDesc('id')->get()->all();
            $slice = $page->slice($items);
            $rows = [];
            foreach ($slice['rows'] as $item) {
                $rows[] = $this->newsDocument($item);
            }

            return ApiResult::ok($this->calls->collection('news', $rows, $page, $slice['total']));
        });
    }

    public function showNews(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $item = News::query()->find($id);
            if (! $item instanceof News || ! in_array((int) $item->id, $this->newsIdsWithModule($actor), true)) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['news' => $this->newsDocument($item)]);
        });
    }

    public function storeNews(User $actor, string $projectKey, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey, $request): ApiResult {
            $project = $this->projectKey($actor, $projectKey);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $this->modules->allow($actor, $project, 'news', 'manage_news');
            $attributes = ApiQuery::resource($request, 'news');
            $item = $this->news->create(
                $actor,
                $project,
                $this->values->text($attributes['title'] ?? null),
                $this->optional($attributes['summary'] ?? null),
                $this->optional($attributes['description'] ?? null),
            );

            return ApiResult::created(['news' => $this->newsDocument($item)]);
        });
    }

    public function updateNews(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $item = News::query()->find($id);
            if (! $item instanceof News) {
                return ApiResult::fail(404, 'Not found');
            }
            $project = $item->project;
            if (! $project instanceof Project) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->modules->allow($actor, $project, 'news', 'manage_news');
            $attributes = ApiQuery::resource($request, 'news');
            $updated = $this->news->update(
                $actor,
                $item,
                array_key_exists('title', $attributes) ? $this->values->text($attributes['title']) : (string) $item->title,
                array_key_exists('summary', $attributes) ? $this->optional($attributes['summary']) : $item->summary,
                array_key_exists('description', $attributes) ? $this->optional($attributes['description']) : $item->description,
            );

            return ApiResult::ok(['news' => $this->newsDocument($updated)]);
        });
    }

    public function destroyNews(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $item = News::query()->find($id);
            if (! $item instanceof News) {
                return ApiResult::fail(404, 'Not found');
            }
            $project = $item->project;
            if (! $project instanceof Project) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->modules->allow($actor, $project, 'news', 'manage_news');
            $this->news->delete($actor, $item);

            return ApiResult::noContent();
        });
    }

    public function wikiIndex(User $actor, string $projectKey): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey): ApiResult {
            $project = $this->projectKey($actor, $projectKey);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $rows = [];
            foreach ($this->wiki->pages($actor, $project) as $page) {
                $rows[] = [
                    'title' => $page['title'],
                    'version' => $page['version'],
                    'updated_on' => $page['updated_on'] === '' ? null : $this->values->stamp($page['updated_on']),
                ];
            }

            return ApiResult::ok(['wiki_pages' => $rows]);
        });
    }

    public function showWiki(User $actor, string $projectKey, string $title): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey, $title): ApiResult {
            $found = $this->wikiPage($actor, $projectKey, $title);
            if ($found instanceof ApiResult) {
                return $found;
            }

            return ApiResult::ok(['wiki_page' => $this->wikiDocument($found['page'], $found['content'])]);
        });
    }

    public function saveWiki(User $actor, string $projectKey, string $title, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey, $title, $request): ApiResult {
            $project = $this->projectKey($actor, $projectKey);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $attributes = ApiQuery::resource($request, 'wiki_page');
            $text = $this->values->text($attributes['text'] ?? '');
            $comments = $this->values->text($attributes['comments'] ?? '');
            $version = is_numeric($attributes['version'] ?? null) ? (int) $attributes['version'] : null;
            $located = $this->wiki->locate($actor, $project, $title);
            if ($located === null) {
                $page = $this->wiki->createPage($actor, $project, $title, $text, $comments, null, false, false);
            } else {
                $this->wiki->updateContent($actor, $located['page'], $text, $comments, false, $version);
                $page = $located['page']->refresh();
            }
            $content = WikiContent::query()->where('page_id', $page->id)->first();
            if (! $content instanceof WikiContent) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['wiki_page' => $this->wikiDocument($page, $content)]);
        });
    }

    public function destroyWiki(User $actor, string $projectKey, string $title): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey, $title): ApiResult {
            $found = $this->wikiPage($actor, $projectKey, $title);
            if ($found instanceof ApiResult) {
                return $found;
            }
            $this->wiki->deletePage($actor, $found['page']);

            return ApiResult::noContent();
        });
    }

    public function boards(User $actor, string $projectKey): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey): ApiResult {
            $project = $this->projectKey($actor, $projectKey);
            if ($project instanceof ApiResult) {
                return $project;
            }

            return ApiResult::ok(['boards' => $this->boards->index($actor, $project)]);
        });
    }

    public function topics(User $actor, int $boardId): ApiResult
    {
        return $this->calls->run(function () use ($actor, $boardId): ApiResult {
            $board = Board::query()->find($boardId);
            if (! $board instanceof Board) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['messages' => $this->messages->topics($actor, $board)]);
        });
    }

    public function storeTopic(User $actor, int $boardId, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $boardId, $request): ApiResult {
            $board = Board::query()->find($boardId);
            if (! $board instanceof Board) {
                return ApiResult::fail(404, 'Not found');
            }
            $attributes = ApiQuery::resource($request, 'message');
            $sticky = is_numeric($attributes['sticky'] ?? null) ? (int) $attributes['sticky'] : 0;
            $locked = $this->flag($attributes['locked'] ?? false);
            $topic = $this->messages->postTopic(
                $actor,
                $board,
                $this->values->text($attributes['subject'] ?? null),
                $this->optional($attributes['content'] ?? null),
                $sticky,
                $locked,
                false,
            );

            return ApiResult::created(['message' => $this->messageDocument($topic)]);
        });
    }

    public function showMessage(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $message = $this->readableMessage($actor, $id);
            if ($message instanceof ApiResult) {
                return $message;
            }

            return ApiResult::ok(['message' => $this->messageDocument($message)]);
        });
    }

    public function replyMessage(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $message = $this->readableMessage($actor, $id);
            if ($message instanceof ApiResult) {
                return $message;
            }
            $attributes = ApiQuery::resource($request, 'message');
            $reply = $this->messages->reply(
                $actor,
                $message,
                $this->optional($attributes['subject'] ?? null),
                $this->optional($attributes['content'] ?? null),
                false,
            );

            return ApiResult::created(['message' => $this->messageDocument($reply)]);
        });
    }

    public function updateMessage(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $message = Message::query()->find($id);
            if (! $message instanceof Message) {
                return ApiResult::fail(404, 'Not found');
            }
            $attributes = ApiQuery::resource($request, 'message');
            $sticky = array_key_exists('sticky', $attributes) && is_numeric($attributes['sticky']) ? (int) $attributes['sticky'] : null;
            $locked = array_key_exists('locked', $attributes) ? $this->flag($attributes['locked']) : null;
            $updated = $this->messages->update(
                $actor,
                $message,
                array_key_exists('subject', $attributes) ? $this->values->text($attributes['subject']) : (string) $message->subject,
                array_key_exists('content', $attributes) ? $this->optional($attributes['content']) : (is_string($message->content) ? $message->content : null),
                $sticky,
                $locked,
            );

            return ApiResult::ok(['message' => $this->messageDocument($updated)]);
        });
    }

    public function destroyMessage(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $message = Message::query()->find($id);
            if (! $message instanceof Message) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->messages->delete($actor, $message);

            return ApiResult::noContent();
        });
    }

    public function upload(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $filename = $request->query('filename');
            if (! is_string($filename) || $filename === '') {
                return ApiResult::fail(422, 'Filename is required.');
            }
            $contentType = $request->query('content_type');
            $uploaded = $this->attachments->upload(
                $actor,
                $filename,
                $request->getContent(),
                is_string($contentType) && $contentType !== '' ? $contentType : null,
            );

            return ApiResult::created(['upload' => ['token' => $uploaded['token']]]);
        });
    }

    public function showAttachment(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $attachment = $this->readableAttachment($actor, $id);
            if ($attachment instanceof ApiResult) {
                return $attachment;
            }

            return ApiResult::ok(['attachment' => $this->attachmentDocument($attachment)]);
        });
    }

    public function destroyAttachment(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $attachment = Attachment::query()->find($id);
            if (! $attachment instanceof Attachment) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->attachments->delete($actor, $attachment);

            return ApiResult::noContent();
        });
    }

    public function files(User $actor, string $projectKey): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey): ApiResult {
            $project = $this->projectKey($actor, $projectKey);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $this->modules->allow($actor, $project, 'files', 'view_files');
            $rows = [];
            foreach ($this->files->grouped($actor, $project, 'filename') as $container) {
                foreach ($container['attachment_ids'] as $attachmentId) {
                    $attachment = Attachment::query()->find($attachmentId);
                    if (! $attachment instanceof Attachment) {
                        continue;
                    }
                    $row = $this->attachmentDocument($attachment);
                    if ($container['container_type'] === 'Version') {
                        $version = Version::query()->find($container['container_id']);
                        if ($version instanceof Version) {
                            $row['version'] = $this->values->ref((int) $version->id, (string) $version->name);
                        }
                    }
                    $rows[] = $row;
                }
            }

            return ApiResult::ok(['files' => $rows]);
        });
    }

    public function storeFile(User $actor, string $projectKey, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $projectKey, $request): ApiResult {
            $project = $this->projectKey($actor, $projectKey);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $this->modules->allow($actor, $project, 'files', 'manage_files');
            $attributes = ApiQuery::resource($request, 'file');
            $token = $attributes['token'] ?? null;
            if (! is_string($token) || $token === '') {
                return ApiResult::fail(422, 'Token is required.');
            }
            $versionId = is_numeric($attributes['version_id'] ?? null) ? (int) $attributes['version_id'] : null;
            $attachment = $this->attachments->claim(
                $actor,
                $token,
                null,
                null,
                is_string($attributes['filename'] ?? null) ? $attributes['filename'] : null,
                is_string($attributes['description'] ?? null) ? $attributes['description'] : null,
                null,
                $versionId === null ? (int) $project->id : null,
                $versionId,
            );

            return ApiResult::created(['file' => $this->attachmentDocument($attachment)]);
        });
    }

    public function search(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $query = $request->query('q');
            if (! is_string($query) || trim($query) === '') {
                return ApiResult::fail(422, 'Query is required.');
            }
            $page = ApiPage::from($request);
            $rows = $this->searchRows($actor, trim($query));
            $slice = $page->slice($rows);

            return ApiResult::ok($this->calls->collection('results', $slice['rows'], $page, $slice['total']));
        });
    }

    public function journals(User $actor, int $issueId): ApiResult
    {
        return $this->calls->run(function () use ($actor, $issueId): ApiResult {
            $issue = $this->readableIssue($actor, $issueId);
            if ($issue instanceof ApiResult) {
                return $issue;
            }

            return ApiResult::ok(['journals' => $this->journalDocuments($actor, $issue)]);
        });
    }

    public function showJournal(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $journal = $this->readableJournal($actor, $id);
            if ($journal instanceof ApiResult) {
                return $journal;
            }

            return ApiResult::ok(['journal' => $this->journalDocument($journal)]);
        });
    }

    public function updateJournal(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $journal = $this->readableJournal($actor, $id);
            if ($journal instanceof ApiResult) {
                return $journal;
            }
            $attributes = ApiQuery::resource($request, 'journal');
            $notes = $attributes['notes'] ?? null;
            if (! is_string($notes)) {
                return ApiResult::fail(422, 'Journal note text cannot be blank.');
            }
            $this->notes->edit($actor, $journal, $notes);

            return ApiResult::noContent();
        });
    }

    public function destroyJournal(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $journal = $this->readableJournal($actor, $id);
            if ($journal instanceof ApiResult) {
                return $journal;
            }
            $this->notes->delete($actor, $journal);

            return ApiResult::noContent();
        });
    }

    /**
     * @return list<TimeEntry>
     */
    private function visibleTimeEntries(User $actor, Request $request): array
    {
        $project = null;
        $projectRaw = $request->query('project_id');
        if ($projectRaw !== null && $projectRaw !== '') {
            $project = $this->locateProject($projectRaw);
            if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
                return [];
            }
        }
        $projects = $project instanceof Project ? [$project] : $this->openProjects($actor);
        $ids = [];
        foreach ($projects as $candidate) {
            if (! $candidate->isModuleEnabled('time_tracking')) {
                continue;
            }
            $query = TimeEntry::query()->where('time_entries.project_id', $candidate->id);
            $this->timeVisibility->apply($query, $actor, $candidate);
            foreach ($query->pluck('time_entries.id') as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $entries = TimeEntry::query()->whereIn('id', $ids)->orderByDesc('spent_on')->orderByDesc('id');
        $issueId = $request->query('issue_id');
        if (is_numeric($issueId)) {
            $entries->where('issue_id', (int) $issueId);
        }
        $userId = $request->query('user_id');
        if ($userId === 'me') {
            $entries->where('user_id', $actor->id);
        } elseif (is_numeric($userId)) {
            $entries->where('user_id', (int) $userId);
        }

        return array_values($entries->get()->all());
    }

    private function readableTimeEntry(User $actor, int $id): TimeEntry|ApiResult
    {
        $entry = TimeEntry::query()->find($id);
        if (! $entry instanceof TimeEntry) {
            return ApiResult::fail(404, 'Not found');
        }
        $project = $entry->project;
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }
        if (! $project->isModuleEnabled('time_tracking')) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }
        $visible = TimeEntry::query()->whereKey($entry->id);
        $this->timeVisibility->apply($visible, $actor, $project);
        if (! $visible->exists()) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function timeProject(User $actor, array $attributes): Project|ApiResult
    {
        if (is_numeric($attributes['issue_id'] ?? null)) {
            $issue = Issue::query()->find((int) $attributes['issue_id']);
            if ($issue instanceof Issue && $issue->project instanceof Project) {
                return $issue->project;
            }
        }
        $raw = $attributes['project_id'] ?? null;
        $project = $this->locateProject($raw);
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(422, 'Project is required.');
        }

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function timeDocument(User $actor, TimeEntry $entry): array
    {
        $entry->loadMissing(['project', 'issue', 'user', 'activity']);
        $project = $entry->project;
        $user = $entry->user;
        $activity = $entry->activity;
        $row = [
            'id' => (int) $entry->id,
            'project' => $project instanceof Project ? $this->values->ref((int) $project->id, (string) $project->name) : null,
        ];
        if ($entry->issue_id !== null) {
            $row['issue'] = ['id' => (int) $entry->issue_id];
        }
        $row['user'] = $user instanceof User ? $this->values->ref((int) $user->id, $this->values->personName($user)) : null;
        $row['activity'] = $activity !== null ? $this->values->ref((int) $activity->id, (string) $activity->name) : null;
        $row['hours'] = (float) $entry->hours;
        $row['comments'] = $this->values->text($entry->comments);
        $row['spent_on'] = $this->values->day($entry->spent_on);
        $row['created_on'] = $this->values->stamp($entry->created_on);
        $row['updated_on'] = $this->values->stamp($entry->updated_on);
        $row['custom_fields'] = $this->values->customFields($actor, $entry);

        return $row;
    }

    private function readableIssue(User $actor, int $id): Issue|ApiResult
    {
        $issue = Issue::query()->find($id);
        if (! $issue instanceof Issue) {
            return ApiResult::fail(404, 'Not found');
        }
        $project = $issue->project;
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }
        if (! $project->isModuleEnabled('issue_tracking') || ! $this->issues->canSee($actor, $issue)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $issue;
    }

    /**
     * @return array<string, mixed>
     */
    private function relationDocument(Issue $issue, IssueRelation $relation): array
    {
        $forward = (int) $relation->issue_from_id === (int) $issue->id;
        $type = (string) $relation->relation_type;
        if (! $forward) {
            $type = IssueRelationService::REVERSE[$type] ?? $type;
        }

        return [
            'id' => (int) $relation->id,
            'issue_id' => (int) $issue->id,
            'issue_to_id' => $forward ? (int) $relation->issue_to_id : (int) $relation->issue_from_id,
            'relation_type' => $type,
            'delay' => $relation->getAttribute('delay') === null ? null : (int) $relation->getAttribute('delay'),
        ];
    }

    /**
     * @return list<int>
     */
    private function newsIdsWithModule(User $actor): array
    {
        $ids = [];
        foreach (Project::query()->orderBy('id')->get() as $project) {
            if (! $project->isModuleEnabled('news')) {
                continue;
            }
            if (! $this->permissions->allowed($actor, 'view_news', $project)) {
                continue;
            }
            foreach ($this->news->visibleIds($actor, $project) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function newsDocument(News $item): array
    {
        $item->loadMissing(['project', 'author']);
        $project = $item->project;
        $author = $item->author;

        return [
            'id' => (int) $item->id,
            'project' => $project instanceof Project ? $this->values->ref((int) $project->id, (string) $project->name) : null,
            'author' => $author instanceof User ? $this->values->ref((int) $author->id, $this->values->personName($author)) : null,
            'title' => (string) $item->title,
            'summary' => $this->values->text($item->summary),
            'description' => $this->values->text($item->description),
            'created_on' => $this->values->stamp($item->created_on),
        ];
    }

    /**
     * @return array{page: WikiPage, content: WikiContent}|ApiResult
     */
    private function wikiPage(User $actor, string $projectKey, string $title): array|ApiResult
    {
        $project = $this->projectKey($actor, $projectKey);
        if ($project instanceof ApiResult) {
            return $project;
        }
        $located = $this->wiki->locate($actor, $project, $title);
        if ($located === null) {
            return ApiResult::fail(404, 'Not found');
        }
        $content = WikiContent::query()->where('page_id', $located['page']->id)->first();
        if (! $content instanceof WikiContent) {
            return ApiResult::fail(404, 'Not found');
        }

        return ['page' => $located['page'], 'content' => $content];
    }

    private function readableMessage(User $actor, int $id): Message|ApiResult
    {
        $message = Message::query()->find($id);
        if (! $message instanceof Message) {
            return ApiResult::fail(404, 'Not found');
        }
        $this->messages->html($actor, $message);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageDocument(Message $message): array
    {
        $author = User::query()->find($message->author_id);

        return [
            'id' => (int) $message->id,
            'board_id' => (int) $message->board_id,
            'parent_id' => $message->parent_id === null ? null : (int) $message->parent_id,
            'subject' => (string) $message->subject,
            'content' => is_string($message->content) ? $message->content : null,
            'author' => $author instanceof User ? $this->values->ref((int) $author->id, $this->values->personName($author)) : null,
            'sticky' => (int) $message->sticky,
            'locked' => (bool) $message->locked,
            'replies_count' => (int) $message->replies_count,
            'created_on' => $this->values->stamp($message->created_on),
            'updated_on' => $this->values->stamp($message->updated_on),
        ];
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * @return array<string, mixed>
     */
    private function wikiDocument(WikiPage $page, WikiContent $content): array
    {
        $author = User::query()->find($content->author_id);
        $parent = $page->parent_id === null ? null : WikiPage::query()->find($page->parent_id);

        return [
            'title' => (string) $page->title,
            'parent' => $parent instanceof WikiPage ? ['title' => (string) $parent->title] : null,
            'text' => $this->values->text($content->text),
            'version' => (int) $content->version,
            'author' => $author instanceof User ? $this->values->ref((int) $author->id, $this->values->personName($author)) : null,
            'comments' => $this->values->text($content->comments),
            'created_on' => $this->values->stamp($page->created_on),
            'updated_on' => $this->values->stamp($content->updated_on),
        ];
    }

    private function readableAttachment(User $actor, int $id): Attachment|ApiResult
    {
        $attachment = Attachment::query()->find($id);
        if (! $attachment instanceof Attachment) {
            return ApiResult::fail(404, 'Not found');
        }
        $type = (string) $attachment->container_type;
        $containerId = (int) $attachment->container_id;
        if ($type === '' || $containerId === 0) {
            if ((int) $attachment->author_id === (int) $actor->id || ($actor->admin && $actor->isActive())) {
                return $attachment;
            }

            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }
        if ($type === 'Issue') {
            $issue = $this->readableIssue($actor, $containerId);

            return $issue instanceof ApiResult ? $issue : $attachment;
        }
        if ($type === 'Project' || $type === 'Version') {
            $project = $type === 'Project'
                ? Project::query()->find($containerId)
                : Version::query()->find($containerId)?->project;
            if (! $project instanceof Project) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->modules->allow($actor, $project, 'files', 'view_files');

            return $attachment;
        }

        return $attachment;
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentDocument(Attachment $attachment): array
    {
        $attachment->loadMissing('author');
        $author = $attachment->author;

        return [
            'id' => (int) $attachment->id,
            'filename' => (string) $attachment->filename,
            'filesize' => (int) $attachment->filesize,
            'content_type' => $attachment->content_type === null ? null : (string) $attachment->content_type,
            'description' => $this->values->text($attachment->description),
            'content_url' => '/attachments/'.$attachment->id,
            'author' => $author instanceof User ? $this->values->ref((int) $author->id, $this->values->personName($author)) : null,
            'created_on' => $this->values->stamp($attachment->created_on),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchRows(User $actor, string $query): array
    {
        $like = '%'.addcslashes($query, '\\%_').'%';
        $rows = [];
        foreach ($this->openProjects($actor) as $project) {
            if ($project->isModuleEnabled('issue_tracking') && $this->permissions->allowed($actor, 'view_issues', $project)) {
                $issues = Issue::query()->where('project_id', $project->id);
                $this->issues->apply($issues, $actor, $project);
                $issues->where(function (Builder $inner) use ($like): void {
                    $inner->where('subject', 'like', $like)->orWhere('description', 'like', $like);
                });
                foreach ($issues->orderByDesc('id')->get() as $issue) {
                    $status = IssueStatus::query()->find($issue->status_id);
                    $rows[] = $this->hit(
                        (int) $issue->id,
                        (string) $issue->subject,
                        $status instanceof IssueStatus && $status->is_closed ? 'issue-closed' : 'issue',
                        '/issues/'.$issue->id,
                        $this->values->text($issue->description),
                        $this->values->stamp($issue->updated_on),
                    );
                }
            }
            if ($this->permissions->projectVisible($actor, $project) && $this->matches($like, (string) $project->name, (string) $project->identifier, $this->values->text($project->description))) {
                $rows[] = $this->hit(
                    (int) $project->id,
                    (string) $project->name,
                    'project',
                    '/projects/'.$project->identifier,
                    $this->values->text($project->description),
                    $this->values->stamp($project->updated_on),
                );
            }
        }
        foreach ($this->newsIdsWithModule($actor) as $id) {
            $item = News::query()->find($id);
            if ($item instanceof News && $this->matches($like, (string) $item->title, $this->values->text($item->summary), $this->values->text($item->description))) {
                $rows[] = $this->hit((int) $item->id, (string) $item->title, 'news', '/news/'.$item->id, $this->values->text($item->description), $this->values->stamp($item->created_on));
            }
        }
        foreach (Project::query()->orderBy('id')->get() as $project) {
            try {
                foreach ($this->wiki->pages($actor, $project) as $page) {
                    $model = WikiPage::query()->find($page['id']);
                    $content = $model instanceof WikiPage ? WikiContent::query()->where('page_id', $model->id)->first() : null;
                    $text = $content instanceof WikiContent ? $this->values->text($content->text) : '';
                    if ($this->matches($like, $page['title'], $text)) {
                        $rows[] = $this->hit(
                            $page['id'],
                            $page['title'],
                            'wiki-page',
                            '/projects/'.$project->identifier.'/wiki/'.$page['title'],
                            $text,
                            $page['updated_on'] === '' ? null : $this->values->stamp($page['updated_on']),
                        );
                    }
                }
            } catch (PermissionDeniedException) {
                // A disabled wiki module or a missing view permission skips that project.
            }
            try {
                foreach ($this->boards->index($actor, $project) as $boardRow) {
                    $board = Board::query()->find($boardRow['id']);
                    if (! $board instanceof Board) {
                        continue;
                    }
                    if ($this->matches($like, $boardRow['name'], $this->values->text($boardRow['description']))) {
                        $rows[] = $this->hit($boardRow['id'], $boardRow['name'], 'board', '/boards/'.$board->id, $this->values->text($boardRow['description']), null);
                    }
                    foreach ($this->messages->topics($actor, $board) as $topic) {
                        $stored = Message::query()->find($topic['id']);
                        $content = $stored instanceof Message ? $this->values->text($stored->content) : '';
                        if ($this->matches($like, $topic['subject'], $content)) {
                            $rows[] = $this->hit($topic['id'], $topic['subject'], 'message', '/boards/'.$board->id.'/topics/'.$topic['id'], $content, $this->values->stamp($topic['updated_on']));
                        }
                    }
                }
            } catch (PermissionDeniedException) {
                // A disabled boards module or a missing view permission skips that project.
            }
            if ($project->isModuleEnabled('documents') && $this->permissions->allowed($actor, 'view_documents', $project)) {
                foreach (Document::query()->where('project_id', $project->id)->orderBy('id')->get() as $document) {
                    if ($this->matches($like, (string) $document->title, $this->values->text($document->description))) {
                        $rows[] = $this->hit((int) $document->id, (string) $document->title, 'document', '/documents/'.$document->id, $this->values->text($document->description), $this->values->stamp($document->created_on));
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * @return array{id: int, title: string, type: string, url: string, description: string, datetime: string|null}
     */
    private function hit(int $id, string $title, string $type, string $url, string $description, ?string $datetime): array
    {
        $flat = trim(preg_replace('/\s+/', ' ', $description) ?? '');
        if (strlen($flat) > 255) {
            $flat = substr($flat, 0, 255);
        }

        return [
            'id' => $id,
            'title' => $title,
            'type' => $type,
            'url' => $url,
            'description' => $flat,
            'datetime' => $datetime,
        ];
    }

    private function matches(string $like, string ...$fields): bool
    {
        $needle = str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], trim($like, '%'));
        $needle = strtolower($needle);
        foreach ($fields as $field) {
            if ($needle !== '' && str_contains(strtolower($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journalDocuments(User $actor, Issue $issue): array
    {
        $rows = [];
        $journals = Journal::query()
            ->where('journalized_type', 'Issue')
            ->where('journalized_id', $issue->id)
            ->orderBy('id')
            ->get();
        $project = $issue->project;
        foreach ($journals as $journal) {
            if ($journal->private_notes && $project instanceof Project && ! ($actor->admin && $actor->isActive()) && ! $this->permissions->allowed($actor, 'view_private_notes', $project)) {
                continue;
            }
            $rows[] = $this->journalDocument($journal);
        }

        return $rows;
    }

    private function readableJournal(User $actor, int $id): Journal|ApiResult
    {
        $journal = Journal::query()->find($id);
        if (! $journal instanceof Journal || (string) $journal->journalized_type !== 'Issue') {
            return ApiResult::fail(404, 'Not found');
        }
        $issue = $this->readableIssue($actor, (int) $journal->journalized_id);
        if ($issue instanceof ApiResult) {
            return $issue;
        }
        $project = $issue->project;
        if ($journal->private_notes && $project instanceof Project && ! ($actor->admin && $actor->isActive()) && ! $this->permissions->allowed($actor, 'view_private_notes', $project)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $journal;
    }

    /**
     * @return array<string, mixed>
     */
    private function journalDocument(Journal $journal): array
    {
        $journal->loadMissing(['user', 'details']);
        $user = $journal->user;
        $details = [];
        foreach ($journal->details()->orderBy('id')->get() as $detail) {
            $details[] = [
                'property' => (string) $detail->property,
                'name' => (string) $detail->prop_key,
                'old_value' => $detail->old_value === null ? null : (string) $detail->old_value,
                'new_value' => $detail->value === null ? null : (string) $detail->value,
            ];
        }

        return [
            'id' => (int) $journal->id,
            'user' => $user instanceof User ? $this->values->ref((int) $user->id, $this->values->personName($user)) : null,
            'notes' => $journal->notes === null ? null : (string) $journal->notes,
            'created_on' => $this->values->stamp($journal->created_on),
            'private_notes' => (bool) $journal->private_notes,
            'details' => $details,
        ];
    }

    private function projectKey(User $actor, string $key): Project|ApiResult
    {
        $project = $this->locateProject($key);
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }

        return $project;
    }

    private function locateProject(mixed $raw): ?Project
    {
        if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
            $found = Project::query()->find((int) $raw);

            return $found instanceof Project ? $found : null;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $found = Project::query()->where('identifier', $raw)->first();

        return $found instanceof Project ? $found : null;
    }

    /**
     * @return list<Project>
     */
    private function openProjects(User $actor): array
    {
        $rows = [];
        foreach (Project::query()->orderBy('id')->get() as $project) {
            if ($this->permissions->projectVisible($actor, $project)) {
                $rows[] = $project;
            }
        }

        return $rows;
    }

    private function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = $this->values->text($value);

        return $text === '' ? null : $text;
    }
}
