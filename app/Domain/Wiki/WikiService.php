<?php

namespace App\Domain\Wiki;

use App\Domain\Acl\ModuleGate;
use App\Domain\Attachments\AttachmentService;
use App\Domain\Attachments\AttachmentThumbnailRenderer;
use App\Domain\Attachments\UnboundAttachment;
use App\Domain\DomainException;
use App\Domain\Watchers\WatcherLedger;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Models\Wiki;
use App\Models\WikiContent;
use App\Models\WikiContentVersion;
use App\Models\WikiPage;
use App\Models\WikiRedirect;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Wiki pages, versions, redirects, protection, attachments, and watchers.
 *
 * A disabled `wiki` module denies every user, including an active administrator.
 * Stored text is not rendered.
 */
final class WikiService
{
    public function __construct(
        private readonly ModuleGate $gate,
        private readonly WikiRedirects $redirects,
        private readonly AttachmentService $files,
        private readonly AttachmentThumbnailRenderer $thumbnails,
        private readonly UnboundAttachment $tokens,
        private readonly WatcherLedger $watchers,
        private readonly WikiNotifier $notifications,
    ) {}

    public function open(User $actor, Project $project): Wiki
    {
        $this->gate->allow($actor, $project, 'wiki', 'view_wiki_pages');

        return $this->wiki($project);
    }

    public function setStartPage(User $actor, Project $project, string $title): Wiki
    {
        $this->gate->allow($actor, $project, 'wiki', 'manage_wiki');
        $wiki = $this->wiki($project);
        $wiki->start_page = WikiTitle::require($title);
        $wiki->save();

        return $wiki->refresh();
    }

    public function destroy(User $actor, Project $project): void
    {
        $this->gate->allow($actor, $project, 'wiki', 'manage_wiki');
        $wiki = Wiki::query()->where('project_id', $project->id)->first();
        if (! $wiki instanceof Wiki) {
            return;
        }
        DB::transaction(function () use ($wiki): void {
            $pageIds = [];
            foreach (WikiPage::query()->where('wiki_id', $wiki->id)->get() as $page) {
                $pageIds[] = (int) $page->id;
            }
            $this->forgetPageSideRows($pageIds);
            WikiRedirect::query()->where('wiki_id', $wiki->id)->delete();
            WikiPage::query()->where('wiki_id', $wiki->id)->delete();
            $wiki->delete();
        });
    }

    public function createPage(
        User $actor,
        Project $project,
        string $title,
        string $text,
        string $comments = '',
        ?int $parentId = null,
        bool $protected = false,
        bool $notify = true,
    ): WikiPage {
        $this->gate->allow($actor, $project, 'wiki', 'edit_wiki_pages');
        if ($protected) {
            $this->gate->allow($actor, $project, 'wiki', 'protect_wiki_pages');
        }
        $storedTitle = WikiTitle::require($title);
        $storedText = WikiText::normalize($text);
        $storedComments = $this->comments($comments);
        $wiki = $this->wiki($project);
        if ($this->pageByTitle($wiki, $storedTitle) instanceof WikiPage) {
            throw new DomainException('Wiki page title is already used.');
        }

        $page = null;
        $content = null;
        DB::transaction(function () use ($actor, $wiki, $storedTitle, $storedText, $storedComments, $parentId, $protected, &$page, &$content): void {
            if ($parentId !== null) {
                $this->assertParent($wiki, null, $parentId);
            }
            $page = WikiPage::query()->create([
                'wiki_id' => $wiki->id,
                'title' => $storedTitle,
                'parent_id' => $parentId,
                'created_on' => now(),
            ]);
            $page->markProtected($protected);
            $page->save();
            $content = $this->writeContent($actor, $page, $storedText, $storedComments, 1);
        });
        if (! $page instanceof WikiPage || ! $content instanceof WikiContent) {
            throw new DomainException('Wiki page could not be stored.');
        }
        if ($notify) {
            $this->notifications->saved($actor, $project, $page, $content, true);
        }

        return $page->refresh();
    }

    public function updateContent(User $actor, WikiPage $page, string $text, string $comments, bool $notify = true): WikiContent
    {
        $project = $this->projectOf($page);
        $this->assertCanEdit($actor, $project, $page);
        $storedText = WikiText::normalize($text);
        $storedComments = $this->comments($comments);
        $content = $this->contentOf($page);
        if ((string) $content->text === $storedText && (string) $content->comments === $storedComments) {
            return $content;
        }

        $saved = DB::transaction(function () use ($actor, $page, $content, $storedText, $storedComments): WikiContent {
            return $this->writeContent($actor, $page, $storedText, $storedComments, (int) $content->version + 1, $content);
        });
        if ($notify) {
            $this->notifications->saved($actor, $project, $page, $saved, false);
        }

        return $saved;
    }

    public function move(User $actor, WikiPage $page, ?int $parentId): WikiPage
    {
        $project = $this->projectOf($page);
        $this->assertCanEdit($actor, $project, $page);
        $wiki = $this->wikiOf($page);
        DB::transaction(function () use ($wiki, $page, $parentId): void {
            $this->assertParent($wiki, $page, $parentId);
            $page->parent_id = $parentId;
            $page->save();
        });

        return $page->refresh();
    }

    public function protect(User $actor, WikiPage $page, bool $protected): WikiPage
    {
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'protect_wiki_pages');
        $page->markProtected($protected);
        $page->save();

        return $page->refresh();
    }

    public function rename(User $actor, WikiPage $page, string $title): WikiPage
    {
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'rename_wiki_pages');
        if ($page->isProtected()) {
            $this->gate->allow($actor, $project, 'wiki', 'protect_wiki_pages');
        }
        $stored = WikiTitle::require($title);
        $wiki = $this->wikiOf($page);
        $current = (string) $page->title;
        if ($stored === $current) {
            return $page;
        }
        $other = $this->pageByTitle($wiki, $stored);
        if ($other instanceof WikiPage && (int) $other->id !== (int) $page->id) {
            throw new DomainException('Wiki page title is already used.');
        }
        DB::transaction(function () use ($wiki, $page, $current, $stored): void {
            $page->title = $stored;
            $page->save();
            $this->redirects->record((int) $wiki->id, $current, $stored);
        });

        return $page->refresh();
    }

    public function deletePage(User $actor, WikiPage $page): void
    {
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'delete_wiki_pages');
        $wiki = $this->wikiOf($page);
        DB::transaction(function () use ($wiki, $page): void {
            WikiPage::query()->where('parent_id', $page->id)->update(['parent_id' => null]);
            $this->redirects->forget((int) $wiki->id, (string) $page->title);
            $this->forgetPageSideRows([(int) $page->id]);
            $page->delete();
        });
    }

    public function deleteVersion(User $actor, WikiPage $page, int $version): WikiContent
    {
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'delete_wiki_pages');
        $content = $this->contentOf($page);

        return DB::transaction(function () use ($content, $version): WikiContent {
            $snapshot = WikiContentVersion::query()
                ->where('wiki_content_id', $content->id)
                ->where('version', $version)
                ->first();
            if (! $snapshot instanceof WikiContentVersion) {
                throw new DomainException('Wiki version does not exist.');
            }
            $count = WikiContentVersion::query()->where('wiki_content_id', $content->id)->count();
            if ($count <= 1) {
                throw new DomainException('The only wiki version cannot be deleted.');
            }
            if ($version === (int) $content->version) {
                $previous = WikiContentVersion::query()
                    ->where('wiki_content_id', $content->id)
                    ->where('version', '<', $version)
                    ->orderByDesc('version')
                    ->first();
                if (! $previous instanceof WikiContentVersion) {
                    throw new DomainException('The only wiki version cannot be deleted.');
                }
                $content->text = WikiContentCodec::unpack($previous->data, $previous->compression);
                $content->author_id = $previous->author_id;
                $content->comments = (string) $previous->comments;
                $content->version = (int) $previous->version;
                $content->updated_on = $previous->updated_on;
                $content->save();
            }
            $snapshot->delete();

            return $content->refresh();
        });
    }

    /**
     * @return array{id: int, title: string, redirected: bool, from: string|null, text: string, version: int, protected: bool, parent_id: int|null}
     */
    public function find(User $actor, Project $project, string $title): array
    {
        $this->gate->allow($actor, $project, 'wiki', 'view_wiki_pages');
        $wiki = $this->wiki($project);
        $lookup = $title === '' ? (string) $wiki->start_page : WikiTitle::require($title);
        $page = $this->pageByTitle($wiki, $lookup);
        $from = null;
        if (! $page instanceof WikiPage) {
            $target = $this->redirects->target((int) $wiki->id, $lookup);
            if ($target !== null) {
                $page = $this->pageByTitle($wiki, $target);
                $from = $lookup;
            }
        }
        if (! $page instanceof WikiPage) {
            throw new DomainException('Wiki page does not exist.');
        }
        $content = $this->contentOf($page);

        return [
            'id' => (int) $page->id,
            'title' => (string) $page->title,
            'redirected' => $from !== null,
            'from' => $from,
            'text' => is_string($content->text) ? $content->text : '',
            'version' => (int) $content->version,
            'protected' => $page->isProtected(),
            'parent_id' => $page->parent_id === null ? null : (int) $page->parent_id,
        ];
    }

    /**
     * @return list<array{version: int, author_id: int|null, comments: string, updated_on: string, text: string, compression: string}>
     */
    public function history(User $actor, WikiPage $page): array
    {
        $this->gate->allow($actor, $this->projectOf($page), 'wiki', 'view_wiki_edits');
        $rows = [];
        foreach (
            WikiContentVersion::query()
                ->where('page_id', $page->id)
                ->orderBy('version')
                ->get() as $snapshot
        ) {
            $updated = $snapshot->getAttribute('updated_on');
            $rows[] = [
                'version' => (int) $snapshot->version,
                'author_id' => is_numeric($snapshot->author_id) ? (int) $snapshot->author_id : null,
                'comments' => (string) $snapshot->comments,
                'updated_on' => $updated instanceof DateTimeInterface ? $updated->format('Y-m-d H:i:s') : '',
                'text' => WikiContentCodec::unpack($snapshot->data, $snapshot->compression),
                'compression' => (string) $snapshot->compression,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{op: string, text: string}>
     */
    public function diff(User $actor, WikiPage $page, int $from, int $to): array
    {
        $history = $this->history($actor, $page);
        $left = $this->snapshotText($history, $from);
        $right = $this->snapshotText($history, $to);

        return WikiText::diff(WikiText::lines($left), WikiText::lines($right));
    }

    /**
     * @return list<array{line: string, version: int, author_id: int|null, updated_on: string}>
     */
    public function annotate(User $actor, WikiPage $page, ?int $version = null): array
    {
        $history = $this->history($actor, $page);
        $through = $version ?? (int) $this->contentOf($page)->version;

        return WikiText::annotate($history, $through);
    }

    /**
     * @return array{title: string, version: int, text: string}
     */
    public function export(User $actor, WikiPage $page): array
    {
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'view_wiki_pages');
        $this->gate->allow($actor, $project, 'wiki', 'export_wiki_pages');
        $content = $this->contentOf($page);

        return [
            'title' => (string) $page->title,
            'version' => (int) $content->version,
            'text' => is_string($content->text) ? $content->text : '',
        ];
    }

    public function attach(User $actor, WikiPage $page, string $token, ?string $filename, ?string $description): Attachment
    {
        $this->assertCanEdit($actor, $this->projectOf($page), $page);
        $attachment = $this->tokens->find($token);

        return DB::transaction(function () use ($attachment, $page, $filename, $description): Attachment {
            $locked = $this->tokens->lock($attachment);
            $this->files->retitle($locked, $filename, $description);
            $this->files->bind($locked, $page);

            return $locked->refresh();
        });
    }

    public function deleteAttachment(User $actor, Attachment $attachment): void
    {
        if ((string) $attachment->container_type !== 'WikiPage') {
            throw new DomainException('Attachment is not attached.');
        }
        $page = WikiPage::query()->find($attachment->container_id);
        if (! $page instanceof WikiPage) {
            throw new DomainException('Attachment is not attached.');
        }
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'delete_wiki_pages_attachments');
        DB::transaction(function () use ($attachment): void {
            $locked = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->first();
            if (! $locked instanceof Attachment) {
                throw new DomainException('Attachment does not exist.');
            }
            $this->thumbnails->forget($locked);
            $this->files->forgetFile($locked);
            $locked->delete();
        });
    }

    public function watch(User $actor, WikiPage $page): void
    {
        $this->gate->allow($actor, $this->projectOf($page), 'wiki', 'view_wiki_pages');
        $this->watchers->add($actor, WatcherLedger::WIKI_PAGE, (int) $page->id);
    }

    public function unwatch(User $actor, WikiPage $page): void
    {
        $this->gate->allow($actor, $this->projectOf($page), 'wiki', 'view_wiki_pages');
        $this->watchers->remove($actor, WatcherLedger::WIKI_PAGE, (int) $page->id);
    }

    public function addWatcher(User $actor, WikiPage $page, User $target): void
    {
        $project = $this->projectOf($page);
        $this->gate->allow($actor, $project, 'wiki', 'add_wiki_page_watchers');
        $this->gate->allow($target, $project, 'wiki', 'view_wiki_pages');
        $this->watchers->add($target, WatcherLedger::WIKI_PAGE, (int) $page->id);
    }

    public function removeWatcher(User $actor, WikiPage $page, User $target): void
    {
        $project = $this->projectOf($page);
        if ((int) $actor->id !== (int) $target->id) {
            $this->gate->allow($actor, $project, 'wiki', 'delete_wiki_page_watchers');
        } else {
            $this->gate->allow($actor, $project, 'wiki', 'view_wiki_pages');
        }
        $this->watchers->remove($target, WatcherLedger::WIKI_PAGE, (int) $page->id);
    }

    /**
     * @return list<int>
     */
    public function watcherIds(User $actor, WikiPage $page): array
    {
        $this->gate->allow($actor, $this->projectOf($page), 'wiki', 'view_wiki_page_watchers');

        return $this->watchers->userIds(WatcherLedger::WIKI_PAGE, (int) $page->id);
    }

    /**
     * @return list<array{title: string, redirects_to: string, redirects_to_wiki_id: int}>
     */
    public function redirectRows(User $actor, Project $project): array
    {
        $this->gate->allow($actor, $project, 'wiki', 'view_wiki_pages');
        $wiki = Wiki::query()->where('project_id', $project->id)->first();
        if (! $wiki instanceof Wiki) {
            return [];
        }

        return $this->redirects->listed((int) $wiki->id);
    }

    private function writeContent(User $actor, WikiPage $page, string $text, string $comments, int $version, ?WikiContent $existing = null): WikiContent
    {
        $content = $existing ?? new WikiContent;
        $content->page_id = $page->id;
        $content->author_id = $actor->id;
        $content->text = $text;
        $content->comments = $comments;
        $content->version = $version;
        $content->updated_on = now();
        $content->save();
        $packed = WikiContentCodec::pack($text);
        WikiContentVersion::query()->create([
            'wiki_content_id' => $content->id,
            'page_id' => $page->id,
            'author_id' => $actor->id,
            'data' => $packed['data'],
            'compression' => $packed['compression'],
            'comments' => $comments,
            'updated_on' => $content->updated_on,
            'version' => $version,
        ]);

        return $content->refresh();
    }

    private function wiki(Project $project): Wiki
    {
        return DB::transaction(function () use ($project): Wiki {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->first();
            if (! $locked instanceof Project) {
                throw new DomainException('Project does not exist.');
            }
            $wiki = Wiki::query()->where('project_id', $locked->id)->orderBy('id')->first();
            if ($wiki instanceof Wiki) {
                return $wiki;
            }

            return Wiki::query()->create([
                'project_id' => $locked->id,
                'start_page' => 'Wiki',
                'status' => 1,
            ]);
        });
    }

    private function assertCanEdit(User $actor, Project $project, WikiPage $page): void
    {
        if ($page->isProtected()) {
            $this->gate->allow($actor, $project, 'wiki', 'protect_wiki_pages');

            return;
        }
        $this->gate->allow($actor, $project, 'wiki', 'edit_wiki_pages');
    }

    private function assertParent(Wiki $wiki, ?WikiPage $page, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if ($page instanceof WikiPage && $parentId === (int) $page->id) {
            throw new DomainException('A wiki page cannot be its own parent.');
        }
        $parent = WikiPage::query()->where('wiki_id', $wiki->id)->find($parentId);
        if (! $parent instanceof WikiPage) {
            throw new DomainException('Parent page is not in this wiki.');
        }
        $cursor = $parent;
        $seen = [];
        while (true) {
            $id = (int) $cursor->id;
            if ($page instanceof WikiPage && $id === (int) $page->id) {
                throw new DomainException('Parent page would create a cycle.');
            }
            if (isset($seen[$id]) || $cursor->parent_id === null) {
                return;
            }
            $seen[$id] = true;
            $next = WikiPage::query()->find($cursor->parent_id);
            if (! $next instanceof WikiPage) {
                return;
            }
            $cursor = $next;
        }
    }

    private function pageByTitle(Wiki $wiki, string $title): ?WikiPage
    {
        $page = WikiPage::query()
            ->where('wiki_id', $wiki->id)
            ->whereRaw('LOWER(title) = LOWER(?)', [$title])
            ->first();

        return $page instanceof WikiPage ? $page : null;
    }

    private function contentOf(WikiPage $page): WikiContent
    {
        $content = WikiContent::query()->where('page_id', $page->id)->first();
        if (! $content instanceof WikiContent) {
            throw new DomainException('Wiki page does not exist.');
        }

        return $content;
    }

    private function wikiOf(WikiPage $page): Wiki
    {
        $wiki = Wiki::query()->find($page->wiki_id);
        if (! $wiki instanceof Wiki) {
            throw new DomainException('Wiki page does not exist.');
        }

        return $wiki;
    }

    private function projectOf(WikiPage $page): Project
    {
        $project = Project::query()->find($this->wikiOf($page)->project_id);
        if (! $project instanceof Project) {
            throw new DomainException('Wiki page does not exist.');
        }

        return $project;
    }

    /**
     * @param  list<array{version: int, author_id: int|null, comments: string, updated_on: string, text: string, compression: string}>  $history
     */
    private function snapshotText(array $history, int $version): string
    {
        foreach ($history as $row) {
            if ($row['version'] === $version) {
                return $row['text'];
            }
        }
        throw new DomainException('Wiki version does not exist.');
    }

    private function comments(string $comments): string
    {
        if (strlen($comments) > 1024) {
            throw new DomainException('Wiki comment is too long.');
        }

        return $comments;
    }

    /**
     * @param  list<int>  $pageIds
     */
    private function forgetPageSideRows(array $pageIds): void
    {
        if ($pageIds === []) {
            return;
        }
        foreach (
            Attachment::query()
                ->where('container_type', 'WikiPage')
                ->whereIn('container_id', $pageIds)
                ->get() as $attachment
        ) {
            $this->files->forgetFile($attachment);
            $attachment->delete();
        }
        foreach ($pageIds as $pageId) {
            $this->watchers->forget(WatcherLedger::WIKI_PAGE, $pageId);
        }
        WikiContentVersion::query()->whereIn('page_id', $pageIds)->delete();
        WikiContent::query()->whereIn('page_id', $pageIds)->delete();
    }
}
