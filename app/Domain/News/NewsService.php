<?php

namespace App\Domain\News;

use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\Notifications\ModuleNotifier;
use App\Domain\PermissionDeniedException;
use App\Models\Comment;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Models\Watcher;
use Illuminate\Support\Facades\DB;

/**
 * News rows, comments, and watchers.
 *
 * Listing requires `view_news`. A project index denies the actor when that
 * check fails. The cross-project index returns every news row on a project
 * the actor may view, newest `created_on` first and then id descending.
 * `manage_news` edits and deletes the row and deletes a comment.
 * `comment_news` adds a comment and increments `comments_count`.
 * A watcher row is the actor watching that news. The actor must be an active
 * user who can view it. Creating news does not add the author as a watcher.
 */
final class NewsService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly ModuleNotifier $notifications,
    ) {}

    public function create(User $actor, Project $project, string $title, ?string $summary, ?string $description): News
    {
        if (! $this->permissions->allowed($actor, 'manage_news', $project)) {
            throw new PermissionDeniedException('manage_news');
        }

        $news = new News([
            'author_id' => (int) $actor->id,
            'comments_count' => 0,
            'created_on' => now(),
            'description' => $this->description($description),
            'project_id' => (int) $project->id,
            'summary' => $this->summary($summary),
            'title' => $this->title($title),
        ]);
        $news->save();
        $fresh = $news->refresh();
        $fresh->load('project');
        $this->notifications->newsAdded($actor, $fresh);

        return $fresh;
    }

    public function update(User $actor, News $news, string $title, ?string $summary, ?string $description): News
    {
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'manage_news', $project)) {
            throw new PermissionDeniedException('manage_news');
        }

        $news->title = $this->title($title);
        $news->summary = $this->summary($summary);
        $news->description = $this->description($description);
        $news->save();

        return $news->refresh();
    }

    public function delete(User $actor, News $news): void
    {
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'manage_news', $project)) {
            throw new PermissionDeniedException('manage_news');
        }

        DB::transaction(function () use ($news): void {
            Comment::query()
                ->where('commented_type', 'News')
                ->where('commented_id', (int) $news->id)
                ->delete();
            Watcher::query()
                ->where('watchable_type', 'News')
                ->where('watchable_id', (int) $news->id)
                ->delete();
            $news->delete();
        });
    }

    public function addComment(User $actor, News $news, string $content): Comment
    {
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'comment_news', $project)) {
            throw new PermissionDeniedException('comment_news');
        }

        $body = trim($content);
        if ($body === '') {
            throw new DomainException('Comment is required.');
        }
        if (strlen($body) > 65535) {
            throw new DomainException('Comment is too long.');
        }

        $comment = DB::transaction(function () use ($actor, $news, $body): Comment {
            $now = now();
            $comment = new Comment([
                'author_id' => (int) $actor->id,
                'commented_id' => (int) $news->id,
                'commented_type' => 'News',
                'content' => $body,
                'created_on' => $now,
                'updated_on' => $now,
            ]);
            $comment->save();
            $news->increment('comments_count');

            return $comment->refresh();
        });
        $news->refresh();
        $news->load('project');
        $this->notifications->newsCommentAdded($actor, $news, $comment);

        return $comment;
    }

    public function deleteComment(User $actor, News $news, Comment $comment): void
    {
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'manage_news', $project)) {
            throw new PermissionDeniedException('manage_news');
        }
        $this->assertComment($news, $comment);

        DB::transaction(function () use ($news, $comment): void {
            $comment->delete();
            if ((int) $news->comments_count > 0) {
                $news->decrement('comments_count');
            }
        });
    }

    public function watch(User $actor, News $news): void
    {
        $this->assertCanWatch($actor, $news);
        $exists = Watcher::query()
            ->where('watchable_type', 'News')
            ->where('watchable_id', (int) $news->id)
            ->where('user_id', (int) $actor->id)
            ->exists();
        if ($exists) {
            return;
        }

        $watcher = new Watcher([
            'user_id' => (int) $actor->id,
            'watchable_id' => (int) $news->id,
            'watchable_type' => 'News',
        ]);
        $watcher->save();
    }

    public function unwatch(User $actor, News $news): void
    {
        $this->assertCanWatch($actor, $news);
        Watcher::query()
            ->where('watchable_type', 'News')
            ->where('watchable_id', (int) $news->id)
            ->where('user_id', (int) $actor->id)
            ->delete();
    }

    /**
     * @return list<int>
     */
    public function watcherIds(?User $actor, News $news): array
    {
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'view_news', $project)) {
            throw new PermissionDeniedException('view_news');
        }

        $ids = [];
        $rows = Watcher::query()
            ->where('watchable_type', 'News')
            ->where('watchable_id', (int) $news->id)
            ->orderBy('user_id')
            ->pluck('user_id');
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * News ids newest first. A project argument denies when `view_news` fails.
     * Null lists every visible project.
     *
     * @return list<int>
     */
    public function visibleIds(?User $actor, ?Project $project): array
    {
        if ($project !== null) {
            if (! $this->permissions->allowed($actor, 'view_news', $project)) {
                throw new PermissionDeniedException('view_news');
            }

            return $this->idsForProjects([(int) $project->id]);
        }

        $projectIds = [];
        $projects = Project::query()->orderBy('id')->get();
        foreach ($projects as $candidate) {
            if ($this->permissions->allowed($actor, 'view_news', $candidate)) {
                $projectIds[] = (int) $candidate->id;
            }
        }

        return $this->idsForProjects($projectIds);
    }

    /**
     * @return list<array{id: int, author_id: int, content: string}>
     */
    public function comments(?User $actor, News $news): array
    {
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'view_news', $project)) {
            throw new PermissionDeniedException('view_news');
        }

        $rows = [];
        $comments = Comment::query()
            ->where('commented_type', 'News')
            ->where('commented_id', (int) $news->id)
            ->orderBy('created_on')
            ->orderBy('id')
            ->get();
        foreach ($comments as $comment) {
            $rows[] = [
                'id' => (int) $comment->id,
                'author_id' => (int) $comment->author_id,
                'content' => (string) $comment->content,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    private function idsForProjects(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $ids = [];
        $rows = News::query()
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('created_on')
            ->orderByDesc('id')
            ->pluck('id');
        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function assertCanWatch(User $actor, News $news): void
    {
        if ($actor->type !== User::TYPE_USER || ! $actor->isActive()) {
            throw new PermissionDeniedException('view_news');
        }
        $project = $this->project($news);
        if (! $this->permissions->allowed($actor, 'view_news', $project)) {
            throw new PermissionDeniedException('view_news');
        }
    }

    private function assertComment(News $news, Comment $comment): void
    {
        if ((string) $comment->commented_type !== 'News' || (int) $comment->commented_id !== (int) $news->id) {
            throw new DomainException('Comment is not on this news.');
        }
    }

    private function project(News $news): Project
    {
        $project = $news->project;
        if (! $project instanceof Project) {
            throw new DomainException('News has no project.');
        }

        return $project;
    }

    private function title(string $title): string
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            throw new DomainException('Title is required.');
        }
        if (mb_strlen($trimmed) > 60) {
            throw new DomainException('Title is too long.');
        }

        return $trimmed;
    }

    private function summary(?string $summary): string
    {
        if ($summary === null) {
            return '';
        }
        if (mb_strlen($summary) > 255) {
            throw new DomainException('Summary is too long.');
        }

        return $summary;
    }

    private function description(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }

        return $description;
    }
}
