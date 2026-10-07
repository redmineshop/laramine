<?php

namespace App\Domain\TextFormatting;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\Tracker;
use App\Models\User;
use App\Models\Version;
use App\Models\Wiki;
use App\Models\WikiContent;
use App\Models\WikiPage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves Redmine link targets from the stored rows.
 *
 * Repository, changeset, and source links are not resolved here.
 */
final class LinkCatalog
{
    public function __construct(
        private readonly IssueVisibility $issues,
        private readonly SettingValue $settings,
    ) {}

    public function url(string $path, FormattingContext $context): string
    {
        if (! $context->absolute) {
            return $path;
        }
        $host = trim($this->settings->hostName());
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || preg_match('/\s/', $host) === 1) {
            $host = 'localhost:3000';
        }

        return $this->settings->protocol().'://'.$host.$path;
    }

    public function issue(int $id, ?User $viewer): ?Issue
    {
        $issue = Issue::query()->with(['tracker', 'status', 'priority', 'project'])->find($id);
        if (! $issue instanceof Issue) {
            return null;
        }
        if ($viewer instanceof User && ! $this->issues->canSee($viewer, $issue)) {
            return null;
        }

        return $issue;
    }

    /**
     * @return list<string>
     */
    public function issueClasses(Issue $issue, ?User $viewer): array
    {
        $classes = [
            'issue',
            'tracker-'.(int) $issue->tracker_id,
            'status-'.(int) $issue->status_id,
        ];
        $priority = $issue->priority;
        if ($priority !== null) {
            $classes[] = 'priority-'.(int) $priority->id;
            $classes[] = 'priority-'.(int) $priority->position;
            if ($priority->is_default) {
                $classes[] = 'priority-default';
            }
        }
        if ($issue->status !== null && $issue->status->is_closed) {
            $classes[] = 'closed';
        }
        if ($issue->parent_id !== null) {
            $classes[] = 'child';
        }
        if ((int) $issue->rgt - (int) $issue->lft > 1) {
            $classes[] = 'parent';
        }
        if ($issue->is_private) {
            $classes[] = 'private';
        }
        $closed = $issue->status !== null && $issue->status->is_closed;
        $dueDay = $this->dueDay($issue->getAttribute('due_date'));
        if (! $closed && $dueDay instanceof CarbonInterface && $dueDay->lt(Carbon::today())) {
            $classes[] = 'overdue';
        }
        if ($viewer instanceof User) {
            if ((int) $issue->author_id === (int) $viewer->id) {
                $classes[] = 'created-by-me';
            }
            if ($this->assignedToViewer($issue, $viewer)) {
                $classes[] = 'assigned-to-me';
            }
        }

        return $classes;
    }

    public function issueTitle(Issue $issue): string
    {
        $tracker = $issue->tracker;
        $name = $tracker !== null ? (string) $tracker->name : 'Issue';

        return $name.': '.(string) $issue->subject;
    }

    public function tracker(string $name): ?Tracker
    {
        $tracker = Tracker::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        return $tracker instanceof Tracker ? $tracker : null;
    }

    public function userById(int $id): ?User
    {
        return $this->linkableUser(User::query()->find($id));
    }

    public function userByLogin(string $login): ?User
    {
        return $this->linkableUser(User::query()->where('login', $login)->first());
    }

    public function projectById(int $id): ?Project
    {
        $project = Project::query()->find($id);

        return $project instanceof Project ? $project : null;
    }

    public function projectByIdentifier(string $identifier): ?Project
    {
        $project = Project::query()->where('identifier', $identifier)->first();

        return $project instanceof Project ? $project : null;
    }

    public function projectByName(string $name): ?Project
    {
        $project = Project::query()->where('name', $name)->first();

        return $project instanceof Project ? $project : null;
    }

    public function version(int|string $key, ?Project $project): ?Version
    {
        if (is_int($key) || preg_match('/^\d+$/', (string) $key) === 1) {
            $version = Version::query()->find((int) $key);

            return $version instanceof Version ? $version : null;
        }
        $query = Version::query()->where('name', (string) $key);
        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }
        $version = $query->orderBy('id')->first();

        return $version instanceof Version ? $version : null;
    }

    public function document(int|string $key, ?Project $project): ?Document
    {
        if (is_int($key) || preg_match('/^\d+$/', (string) $key) === 1) {
            $document = Document::query()->find((int) $key);

            return $document instanceof Document ? $document : null;
        }
        $query = Document::query()->where('title', (string) $key);
        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }
        $document = $query->orderBy('id')->first();

        return $document instanceof Document ? $document : null;
    }

    public function news(int|string $key, ?Project $project): ?News
    {
        if (is_int($key) || preg_match('/^\d+$/', (string) $key) === 1) {
            $news = News::query()->find((int) $key);

            return $news instanceof News ? $news : null;
        }
        $query = News::query()->where('title', (string) $key);
        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }
        $news = $query->orderBy('id')->first();

        return $news instanceof News ? $news : null;
    }

    public function message(int $id): ?Message
    {
        $message = Message::query()->find($id);

        return $message instanceof Message ? $message : null;
    }

    public function board(int $id): ?Board
    {
        $board = Board::query()->with('project')->find($id);

        return $board instanceof Board ? $board : null;
    }

    public function boardByName(string $name, ?Project $project): ?Board
    {
        $query = Board::query()->with('project')->where('name', $name);
        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }
        $board = $query->orderBy('id')->first();

        return $board instanceof Board ? $board : null;
    }

    public function wikiStartTitle(Project $project): string
    {
        $wiki = Wiki::query()->where('project_id', $project->id)->first();
        $start = $wiki instanceof Wiki ? trim((string) $wiki->start_page) : '';
        if ($start !== '') {
            return $start;
        }

        return 'Wiki';
    }

    /**
     * Wiki pages whose content changed inside the day window, newest first.
     *
     * @return list<array{page: WikiPage, project: Project, updated_on: string}>
     */
    public function recentWikiPages(Project $project, int $days, ?int $limit, bool $includeSubprojects): array
    {
        $projectIds = [$project->id];
        if ($includeSubprojects) {
            $projectIds = [];
            foreach (Project::query()->where('lft', '>=', $project->lft)->where('rgt', '<=', $project->rgt)->orderBy('id')->get() as $candidate) {
                $projectIds[] = (int) $candidate->id;
            }
        }
        if ($projectIds === []) {
            return [];
        }
        $cutoff = Carbon::now()->subDays(max(0, $days))->format('Y-m-d H:i:s');
        $query = DB::table('wiki_pages')
            ->join('wikis', 'wikis.id', '=', 'wiki_pages.wiki_id')
            ->join('wiki_contents', 'wiki_contents.page_id', '=', 'wiki_pages.id')
            ->whereIn('wikis.project_id', $projectIds)
            ->where('wiki_contents.updated_on', '>=', $cutoff)
            ->orderByDesc('wiki_contents.updated_on')
            ->orderByDesc('wiki_pages.id');
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }
        $found = [];
        foreach ($query->get(['wiki_pages.id as page_id', 'wikis.project_id as project_id', 'wiki_contents.updated_on as updated_on']) as $row) {
            $page = WikiPage::query()->find($row->page_id);
            $owner = Project::query()->find($row->project_id);
            if (! $page instanceof WikiPage || ! $owner instanceof Project) {
                continue;
            }
            $found[] = [
                'page' => $page,
                'project' => $owner,
                'updated_on' => is_string($row->updated_on) ? $row->updated_on : (string) $row->updated_on,
            ];
        }

        return $found;
    }

    public function wikiPage(?Project $project, string $title): ?WikiPage
    {
        if (! $project instanceof Project) {
            return null;
        }
        $wiki = Wiki::query()->where('project_id', $project->id)->first();
        if (! $wiki instanceof Wiki) {
            return null;
        }
        $normalized = mb_strtolower(str_replace('_', ' ', trim($title)));
        $page = WikiPage::query()
            ->where('wiki_id', $wiki->id)
            ->whereRaw('LOWER(title) = ?', [$normalized])
            ->first();

        return $page instanceof WikiPage ? $page : null;
    }

    /**
     * @return list<WikiPage>
     */
    public function wikiChildren(WikiPage $page): array
    {
        $children = [];
        foreach (WikiPage::query()->where('parent_id', $page->id)->orderBy('title')->orderBy('id')->get() as $child) {
            $children[] = $child;
        }

        return $children;
    }

    public function wikiProject(WikiPage $page): ?Project
    {
        $wiki = Wiki::query()->find($page->wiki_id);
        if (! $wiki instanceof Wiki) {
            return null;
        }
        $project = Project::query()->find($wiki->project_id);

        return $project instanceof Project ? $project : null;
    }

    public function wikiContent(WikiPage $page): ?WikiContent
    {
        $content = WikiContent::query()->where('page_id', $page->id)->first();

        return $content instanceof WikiContent ? $content : null;
    }

    public function attachment(FormattingContext $context, string $filename): ?Attachment
    {
        $filename = trim($filename);
        if ($filename === '') {
            return null;
        }
        foreach ($this->containers($context->object) as [$type, $id]) {
            $attachment = Attachment::query()
                ->where('container_type', $type)
                ->where('container_id', $id)
                ->where('filename', $filename)
                ->orderBy('id')
                ->first();
            if ($attachment instanceof Attachment) {
                return $attachment;
            }
        }

        return null;
    }

    public function currentWikiPage(FormattingContext $context): ?WikiPage
    {
        $object = $context->object;
        if ($object instanceof WikiPage) {
            return $object;
        }
        if ($object instanceof WikiContent) {
            $page = WikiPage::query()->find($object->page_id);

            return $page instanceof WikiPage ? $page : null;
        }

        return null;
    }

    public function attachmentPath(Attachment $attachment): string
    {
        return '/attachments/'.(int) $attachment->id.'/'.$this->encodeFilename((string) $attachment->filename);
    }

    public function thumbnailPath(Attachment $attachment, int $size): string
    {
        return '/attachments/thumbnail/'.(int) $attachment->id.'/'.$size;
    }

    public function wikiPath(Project $project, string $title, ?string $anchor = null): string
    {
        $slug = str_replace(' ', '_', trim($title));
        $path = '/projects/'.rawurlencode((string) $project->identifier).'/wiki/'.rawurlencode($slug);
        if ($anchor !== null && $anchor !== '') {
            $path .= '#'.rawurlencode($anchor);
        }

        return $path;
    }

    public function encodeFilename(string $filename): string
    {
        $parts = explode('/', $filename);
        $encoded = [];
        foreach ($parts as $part) {
            $encoded[] = rawurlencode($part);
        }

        return implode('/', $encoded);
    }

    public function personName(User $user): string
    {
        $name = trim((string) $user->firstname.' '.(string) $user->lastname);
        if ($name === '') {
            return (string) $user->login;
        }

        return $name;
    }

    public function userClass(User $user): string
    {
        return match ((int) $user->status) {
            1 => 'user active',
            3 => 'user locked',
            default => 'user',
        };
    }

    private function linkableUser(mixed $user): ?User
    {
        if (! $user instanceof User) {
            return null;
        }
        if ($user->type !== User::TYPE_USER && $user->type !== User::TYPE_GROUP) {
            return null;
        }

        return $user;
    }

    private function dueDay(mixed $due): ?CarbonInterface
    {
        if ($due instanceof CarbonInterface) {
            return $due->copy()->startOfDay();
        }
        if (is_string($due) && trim($due) !== '') {
            return Carbon::parse($due)->startOfDay();
        }

        return null;
    }

    private function assignedToViewer(Issue $issue, User $viewer): bool
    {
        $assignee = $issue->assigned_to_id;
        if ($assignee === null) {
            return false;
        }
        if ((int) $assignee === (int) $viewer->id) {
            return true;
        }

        return DB::table('groups_users')
            ->where('group_id', (int) $assignee)
            ->where('user_id', (int) $viewer->id)
            ->exists();
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function containers(?Model $object): array
    {
        if ($object instanceof Issue) {
            return [['Issue', (int) $object->id]];
        }
        if ($object instanceof Journal) {
            $pairs = [['Journal', (int) $object->id]];
            if ((string) $object->journalized_type === 'Issue') {
                $pairs[] = ['Issue', (int) $object->journalized_id];
            }

            return $pairs;
        }
        if ($object instanceof WikiPage) {
            return [['WikiPage', (int) $object->id]];
        }
        if ($object instanceof WikiContent) {
            return [['WikiPage', (int) $object->page_id]];
        }
        if ($object instanceof News) {
            return [['News', (int) $object->id]];
        }
        if ($object instanceof Message) {
            return [['Message', (int) $object->id]];
        }
        if ($object instanceof Document) {
            return [['Document', (int) $object->id]];
        }
        if ($object instanceof Project) {
            return [['Project', (int) $object->id]];
        }
        if ($object instanceof Version) {
            return [['Version', (int) $object->id]];
        }

        return [];
    }
}
