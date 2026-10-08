<?php

namespace App\Http\Controllers;

use App\Domain\DomainException;
use App\Domain\Wiki\WikiPdf;
use App\Domain\Wiki\WikiService;
use App\Domain\Wiki\WikiText;
use App\Domain\Wiki\WikiTitle;
use App\Domain\Wiki\WikiVersionConflictException;
use App\Http\DomainHttp;
use App\Http\ModulePermission;
use App\Http\ProjectLocator;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiContent;
use App\Models\WikiPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wiki pages. These screens are not a Redmine layout.
 *
 * A guest without permission is redirected to sign-in. A signed-in denial is 403.
 * A stale version or section hash is 409 and does not save.
 */
class WikiController extends Controller
{
    public const UNSUPPORTED_EXPORT = 'Wiki export format is not available. Core formats without an extra gem are html, txt, and pdf.';

    public function __construct(
        private readonly WikiService $wiki,
        private readonly ProjectLocator $projects,
        private readonly ModulePermission $permissions,
        private readonly DomainHttp $http,
        private readonly WikiPdf $pdf,
    ) {}

    public function show(Request $request, string $project, ?string $title = null): InertiaResponse|Response
    {
        return $this->renderShow($request, $project, $title ?? '', null);
    }

    public function showVersion(Request $request, string $project, string $title, string $version): InertiaResponse|Response
    {
        return $this->renderShow($request, $project, $title, $this->versionNumber($version));
    }

    public function index(Request $request, string $project): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        try {
            $pages = $this->wiki->pages($actor, $found);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return Inertia::render('Wiki/Index', [
            'project' => (string) $found->identifier,
            'mode' => 'index',
            'pages' => $this->withSlugs($pages),
            'groups' => [],
            'canEdit' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'edit_wiki_pages'),
            'canManage' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'manage_wiki'),
        ]);
    }

    public function dateIndex(Request $request, string $project): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        try {
            $groups = $this->wiki->dateIndex($actor, $found);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $shaped = [];
        foreach ($groups as $group) {
            $shaped[] = [
                'date' => $group['date'],
                'pages' => $this->withSlugs($group['pages']),
            ];
        }

        return Inertia::render('Wiki/Index', [
            'project' => (string) $found->identifier,
            'mode' => 'date_index',
            'pages' => [],
            'groups' => $shaped,
            'canEdit' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'edit_wiki_pages'),
            'canManage' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'manage_wiki'),
        ]);
    }

    public function exportWiki(Request $request, string $project, ?string $format = null): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        $chosen = $this->exportFormat($request, $format);
        try {
            $rows = $this->wiki->exportAll($actor, $found);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        if (! in_array($chosen, ['html', 'txt', 'pdf'], true)) {
            return response(self::UNSUPPORTED_EXPORT, 406);
        }
        if ($chosen === 'txt') {
            $chunks = [];
            foreach ($rows as $row) {
                $chunks[] = $row['title']."\n".$row['text'];
            }

            return $this->download(implode("\n\n", $chunks), 'text/plain; charset=UTF-8', 'wiki.txt');
        }
        if ($chosen === 'pdf') {
            $lines = [];
            foreach ($rows as $row) {
                $lines[] = $row['title'];
                foreach (WikiText::lines($row['text']) as $line) {
                    $lines[] = $line;
                }
            }

            return $this->download($this->pdf->render((string) $found->identifier, $lines), 'application/pdf', 'wiki.pdf');
        }
        $parts = [];
        foreach ($rows as $row) {
            $located = $this->wiki->locate($actor, $found, $row['title']);
            $body = $located === null ? '' : $this->wiki->html($actor, $located['page']);
            $parts[] = '<section><h1>'.e($row['title']).'</h1>'.$body.'</section>';
        }

        return response($this->htmlDocument('Wiki', implode("\n", $parts)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function exportPage(Request $request, string $project, string $title, string $format): Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        try {
            $exported = $this->wiki->export($actor, $opened);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $chosen = $this->exportFormat($request, $format);
        $filename = WikiTitle::slug($exported['title']);
        if (! in_array($chosen, ['html', 'txt', 'pdf'], true)) {
            return response(self::UNSUPPORTED_EXPORT, 406);
        }
        if ($chosen === 'txt') {
            return $this->download($exported['text'], 'text/plain; charset=UTF-8', $filename.'.txt');
        }
        if ($chosen === 'pdf') {
            return $this->download(
                $this->pdf->render($exported['title'], WikiText::lines($exported['text'])),
                'application/pdf',
                $filename.'.pdf',
            );
        }
        $html = $this->wiki->html($actor, $opened);

        return response($this->htmlDocument($exported['title'], $html), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function create(Request $request, string $project): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->permissions->allows($actor, $found, 'wiki', 'edit_wiki_pages')) {
            return $this->http->denied($request);
        }
        $title = $this->http->queryString($request, 'title') ?? '';

        return Inertia::render('Wiki/Edit', $this->editProps($actor, $found, null, $title, '', '', null, null, null, null));
    }

    public function store(Request $request, string $project): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $page = $this->wiki->createPage(
                $actor,
                $found,
                $this->submittedTitle($request),
                $this->contentField($request, 'text'),
                $this->contentField($request, 'comments'),
                $this->parentId($request),
                $this->http->checked($request, 'protected'),
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->path($found, (string) $page->title));
    }

    public function edit(Request $request, string $project, string $title): InertiaResponse|Response
    {
        return $this->renderEdit($request, $project, $title, null);
    }

    public function editVersion(Request $request, string $project, string $title, string $version): InertiaResponse|Response
    {
        return $this->renderEdit($request, $project, $title, $this->versionNumber($version));
    }

    public function update(Request $request, string $project, string $title): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $located = $this->wiki->locate($actor, $found, $title);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        if ($located !== null && $located['redirected']) {
            return redirect($this->path($found, (string) $located['page']->title));
        }
        if ($located === null) {
            try {
                $page = $this->wiki->createPage(
                    $actor,
                    $found,
                    $title,
                    $this->contentField($request, 'text'),
                    $this->contentField($request, 'comments'),
                    $this->parentId($request),
                    $this->http->checked($request, 'protected'),
                );
            } catch (DomainException $exception) {
                return $this->http->fail($request, $exception);
            }

            return redirect($this->path($found, (string) $page->title));
        }
        $page = $located['page'];
        try {
            if ($request->exists('parent_id') || $this->nestedExists($request, 'wiki_page', 'parent_id')) {
                $this->wiki->move($actor, $page, $this->parentId($request));
            }
            $this->wiki->updateContent(
                $actor,
                $page,
                $this->contentField($request, 'text'),
                $this->contentField($request, 'comments'),
                true,
                $this->submittedVersion($request),
                $this->http->nullableInt($request, 'section'),
                $this->optionalString($request, 'section_hash'),
            );
        } catch (WikiVersionConflictException) {
            return $this->conflict($request, $actor, $found, $page);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->path($found, (string) $page->title));
    }

    public function destroy(Request $request, string $project, string $title): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->permissions->allows($actor, $found, 'wiki', 'delete_wiki_pages')) {
            return $this->http->denied($request);
        }
        $descendants = $this->wiki->descendantCount($actor, $opened);
        $todo = $request->input('todo');
        if ($descendants > 0 && ! is_string($todo)) {
            return Inertia::render('Wiki/Destroy', [
                'project' => (string) $found->identifier,
                'title' => (string) $opened->title,
                'slug' => WikiTitle::slug((string) $opened->title),
                'descendants' => $descendants,
                'pages' => $this->withSlugs($this->wiki->pages($actor, $found)),
            ]);
        }
        try {
            $this->wiki->deletePage($actor, $opened, is_string($todo) ? $todo : 'nullify', $this->http->nullableInt($request, 'reassign_to_id'));
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect('/projects/'.$found->identifier.'/wiki/index');
    }

    public function destroyVersion(Request $request, string $project, string $title, string $version): Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->wiki->deleteVersion($actor, $opened, $this->versionNumber($version));
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->path($found, (string) $opened->title).'/history');
    }

    public function rename(Request $request, string $project, string $title): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->permissions->allows($actor, $found, 'wiki', 'rename_wiki_pages')) {
            return $this->http->denied($request);
        }
        if ($request->isMethod('GET')) {
            return Inertia::render('Wiki/Rename', [
                'project' => (string) $found->identifier,
                'title' => (string) $opened->title,
                'slug' => WikiTitle::slug((string) $opened->title),
            ]);
        }
        try {
            $page = $this->wiki->rename($actor, $opened, $this->submittedTitle($request), $this->http->redirectWanted($request));
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->path($found, (string) $page->title));
    }

    public function protect(Request $request, string $project, string $title): Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        $next = $request->exists('protected') ? $this->http->checked($request, 'protected') : ! $opened->isProtected();
        try {
            $this->wiki->protect($actor, $opened, $next);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->path($found, (string) $opened->title));
    }

    public function history(Request $request, string $project, string $title): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        try {
            $rows = $this->wiki->history($actor, $opened);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $versions = [];
        foreach ($rows as $row) {
            $versions[] = [
                'version' => $row['version'],
                'author_id' => $row['author_id'],
                'comments' => $row['comments'],
                'updated_on' => $row['updated_on'],
            ];
        }

        return Inertia::render('Wiki/History', [
            'project' => (string) $found->identifier,
            'title' => (string) $opened->title,
            'slug' => WikiTitle::slug((string) $opened->title),
            'versions' => $versions,
            'canDelete' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'delete_wiki_pages'),
        ]);
    }

    public function diff(Request $request, string $project, string $title): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        $content = $opened->content()->first();
        $current = $content instanceof WikiContent ? (int) $content->version : 1;
        $to = $this->http->queryInt($request, 'version') ?? $current;
        $from = $this->http->queryInt($request, 'version_from') ?? max(1, $to - 1);
        try {
            $ops = $this->wiki->diff($actor, $opened, $from, $to);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return Inertia::render('Wiki/Diff', [
            'project' => (string) $found->identifier,
            'title' => (string) $opened->title,
            'slug' => WikiTitle::slug((string) $opened->title),
            'from' => $from,
            'to' => $to,
            'ops' => $ops,
        ]);
    }

    public function annotate(Request $request, string $project, string $title, ?string $version = null): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        $selected = $version !== null ? $this->versionNumber($version) : $this->http->queryInt($request, 'version');
        try {
            $lines = $this->wiki->annotate($actor, $opened, $selected);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return Inertia::render('Wiki/Annotate', [
            'project' => (string) $found->identifier,
            'title' => (string) $opened->title,
            'slug' => WikiTitle::slug((string) $opened->title),
            'version' => $selected,
            'lines' => $lines,
        ]);
    }

    public function preview(Request $request, string $project, string $title): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        $page = null;
        try {
            $located = $this->wiki->locate($actor, $found, $title);
            if ($located !== null) {
                $page = $located['page'];
            }
            $html = $this->wiki->preview($actor, $found, $this->contentField($request, 'text'), $page);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function addAttachment(Request $request, string $project, string $title): Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->wiki->attach(
                $actor,
                $opened,
                $this->http->text($request, 'token'),
                $this->optionalString($request, 'filename'),
                $this->optionalString($request, 'description'),
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->path($found, (string) $opened->title));
    }

    public function updateSettings(Request $request, string $project): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->wiki->setStartPage($actor, $found, $this->http->text($request, 'start_page'));
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect('/projects/'.$found->identifier.'/wiki');
    }

    public function destroyWiki(Request $request, string $project): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->wiki->destroy($actor, $found);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect('/projects/'.$found->identifier.'/wiki/index');
    }

    private function renderShow(Request $request, string $project, string $title, ?int $version): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        try {
            $html = $this->wiki->html($actor, $opened, $version);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $content = $opened->content()->first();
        $current = $content instanceof WikiContent ? (int) $content->version : 0;
        $text = $content instanceof WikiContent && is_string($content->text) ? $content->text : '';
        if ($version !== null && $version !== $current && $actor instanceof User) {
            $text = $this->wiki->textAt($actor, $opened, $version);
        }
        $watcherIds = null;
        $watching = false;
        if ($actor instanceof User) {
            $watching = $this->wiki->isWatching($actor, $opened);
            if ($this->permissions->allows($actor, $found, 'wiki', 'view_wiki_page_watchers')) {
                $watcherIds = $this->wiki->watcherIds($actor, $opened);
            }
        }

        return Inertia::render('Wiki/Show', [
            'project' => (string) $found->identifier,
            'page' => [
                'id' => (int) $opened->id,
                'title' => (string) $opened->title,
                'slug' => WikiTitle::slug((string) $opened->title),
                'version' => $version ?? $current,
                'currentVersion' => $current,
                'protected' => $opened->isProtected(),
                'parent_id' => $opened->parent_id === null ? null : (int) $opened->parent_id,
                'text' => $text,
            ],
            'html' => $html,
            'attachments' => $this->attachmentRows('WikiPage', (int) $opened->id),
            'watching' => $watching,
            'watcherIds' => $watcherIds,
            'canEdit' => $actor instanceof User && $this->wiki->canEdit($actor, $opened),
            'canRename' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'rename_wiki_pages'),
            'canDelete' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'delete_wiki_pages'),
            'canProtect' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'protect_wiki_pages'),
            'canExport' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'export_wiki_pages'),
            'canHistory' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'view_wiki_edits'),
            'canManage' => $actor instanceof User && $this->permissions->allows($actor, $found, 'wiki', 'manage_wiki'),
        ]);
    }

    private function renderEdit(Request $request, string $project, string $title, ?int $version): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $opened = $this->openPage($request, $found, $title);
        if (! $opened instanceof WikiPage) {
            return $opened;
        }
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        $section = $this->http->queryInt($request, 'section');
        try {
            if ($version !== null) {
                $text = $this->wiki->textAt($actor, $opened, $version);
                $content = $opened->content()->first();
                $lock = $content instanceof WikiContent ? (int) $content->version : $version;
                $props = $this->editProps($actor, $found, $opened, (string) $opened->title, $text, '', $lock, null, null, null);
            } elseif ($section !== null) {
                $slice = $this->wiki->sectionText($actor, $opened, $section);
                $props = $this->editProps($actor, $found, $opened, (string) $opened->title, $slice['text'], '', $slice['version'], $section, $slice['hash'], null);
            } else {
                $content = $opened->content()->first();
                $text = $content instanceof WikiContent && is_string($content->text) ? $content->text : '';
                $lock = $content instanceof WikiContent ? (int) $content->version : 0;
                $props = $this->editProps($actor, $found, $opened, (string) $opened->title, $text, '', $lock, null, null, null);
            }
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return Inertia::render('Wiki/Edit', $props);
    }

    private function conflict(Request $request, User $actor, Project $project, WikiPage $page): Response
    {
        $content = $page->content()->first();
        $lock = $content instanceof WikiContent ? (int) $content->version : 0;
        $section = $this->http->nullableInt($request, 'section');
        $hash = null;
        if ($section !== null) {
            try {
                $hash = $this->wiki->sectionText($actor, $page, $section)['hash'];
            } catch (DomainException) {
                $hash = null;
            }
        }
        $response = Inertia::render('Wiki/Edit', $this->editProps(
            $actor,
            $project,
            $page,
            (string) $page->title,
            $this->contentField($request, 'text'),
            $this->contentField($request, 'comments'),
            $lock,
            $section,
            $hash,
            WikiVersionConflictException::MESSAGE,
        ))->toResponse($request);
        $response->setStatusCode(409);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function editProps(
        User $actor,
        Project $project,
        ?WikiPage $page,
        string $title,
        string $text,
        string $comments,
        ?int $version,
        ?int $section,
        ?string $sectionHash,
        ?string $conflict,
    ): array {
        $storedTitle = $title === '' ? '' : WikiTitle::titleize($title);

        return [
            'mode' => $page === null ? 'new' : ($section === null ? 'edit' : 'section'),
            'project' => (string) $project->identifier,
            'title' => $storedTitle,
            'slug' => $storedTitle === '' ? '' : WikiTitle::slug($storedTitle),
            'pageId' => $page === null ? null : (int) $page->id,
            'text' => $text,
            'comments' => $comments,
            'version' => $version,
            'section' => $section,
            'sectionHash' => $sectionHash,
            'parentId' => $page === null || $page->parent_id === null ? null : (int) $page->parent_id,
            'protected' => $page instanceof WikiPage && $page->isProtected(),
            'canProtect' => $this->permissions->allows($actor, $project, 'wiki', 'protect_wiki_pages'),
            'pages' => $this->withSlugs($this->wiki->pages($actor, $project)),
            'conflict' => $conflict,
        ];
    }

    private function openPage(Request $request, Project $project, string $title): WikiPage|RedirectResponse|JsonResponse
    {
        $actor = $this->actor($request);
        try {
            $located = $this->wiki->locate($actor, $project, $title);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        if ($located === null) {
            if ($actor instanceof User && $this->permissions->allows($actor, $project, 'wiki', 'edit_wiki_pages')) {
                $target = $title === '' ? 'Wiki' : $title;

                return redirect('/projects/'.$project->identifier.'/wiki/new?title='.rawurlencode($target));
            }
            abort(404);
        }
        if ($located['redirected']) {
            return redirect($this->path($project, (string) $located['page']->title));
        }

        return $located['page'];
    }

    /**
     * @param  list<array{id: int, title: string, parent_id: int|null, protected: bool, version: int, updated_on: string}>  $pages
     * @return list<array{id: int, title: string, slug: string, parent_id: int|null, protected: bool, version: int, updated_on: string}>
     */
    private function withSlugs(array $pages): array
    {
        $rows = [];
        foreach ($pages as $page) {
            $rows[] = [
                'id' => $page['id'],
                'title' => $page['title'],
                'slug' => WikiTitle::slug($page['title']),
                'parent_id' => $page['parent_id'],
                'protected' => $page['protected'],
                'version' => $page['version'],
                'updated_on' => $page['updated_on'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, filename: string}>
     */
    private function attachmentRows(string $type, int $id): array
    {
        $rows = [];
        foreach (Attachment::query()->where('container_type', $type)->where('container_id', $id)->orderBy('id')->get() as $attachment) {
            $rows[] = [
                'id' => (int) $attachment->id,
                'filename' => (string) $attachment->filename,
            ];
        }

        return $rows;
    }

    private function path(Project $project, string $title): string
    {
        return '/projects/'.$project->identifier.'/wiki/'.rawurlencode(WikiTitle::slug($title));
    }

    private function htmlDocument(string $title, string $body): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            .e($title)
            .'</title></head><body><div class="wiki">'.$body.'</div></body></html>';
    }

    private function download(string $body, string $type, string $filename): Response
    {
        $safe = str_replace(['"', "\r", "\n"], '', $filename);

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
        ]);
    }

    private function exportFormat(Request $request, ?string $format): string
    {
        $chosen = $format ?? $this->http->queryString($request, 'format') ?? 'html';

        return strtolower($chosen);
    }

    private function versionNumber(string $version): int
    {
        return (int) $version;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function contentField(Request $request, string $key): string
    {
        $flat = $request->input($key);
        if (is_string($flat)) {
            return $flat;
        }
        $content = $request->input('content');
        if (is_array($content) && isset($content[$key]) && is_string($content[$key])) {
            return $content[$key];
        }

        return '';
    }

    private function submittedVersion(Request $request): ?int
    {
        if ($request->exists('version')) {
            return $this->http->nullableInt($request, 'version');
        }
        $content = $request->input('content');
        if (is_array($content) && array_key_exists('version', $content)) {
            $value = $content['version'];
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        return null;
    }

    private function submittedTitle(Request $request): string
    {
        $flat = $this->http->text($request, 'title');
        if ($flat !== '') {
            return $flat;
        }
        $page = $request->input('wiki_page');
        if (is_array($page) && isset($page['title']) && is_string($page['title'])) {
            return $page['title'];
        }

        return '';
    }

    private function parentId(Request $request): ?int
    {
        if ($request->exists('parent_id')) {
            return $this->http->nullableInt($request, 'parent_id');
        }
        $page = $request->input('wiki_page');
        if (is_array($page) && array_key_exists('parent_id', $page)) {
            $value = $page['parent_id'];
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        return null;
    }

    private function nestedExists(Request $request, string $bag, string $key): bool
    {
        $value = $request->input($bag);

        return is_array($value) && array_key_exists($key, $value);
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
