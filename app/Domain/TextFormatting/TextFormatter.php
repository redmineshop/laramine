<?php

namespace App\Domain\TextFormatting;

use App\Domain\Settings\SettingValue;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Message;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Models\WikiPage;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Illuminate\Support\Carbon;

/**
 * Renders textile, CommonMark, or plain text the way Redmine 7.0.1 does.
 *
 * Macros, wiki links, and Redmine links run for every format. Repository,
 * changeset, and source links are left as text.
 */
final class TextFormatter
{
    private const PATTERN = '/\[\[(?<wiki>[^\]]+)\]\]|(?<![\w&])##(?<fullissue>\d+)(?!\w)|(?<![\w&])#note-(?<samenote>\d+)(?!\w)|(?<proj>[A-Za-z][A-Za-z0-9_-]*):(?<kind>attachment|document|version|news|message|project|user|forum|board)#(?<kid>\d+)|(?<proj2>[A-Za-z][A-Za-z0-9_-]*):(?<kind2>attachment|document|version|news|message|project|user|forum|board):(?:"(?<quoted>[^"]+)"|(?<bare>[^\s<>\]]+))|(?<kind3>attachment|document|version|news|message|project|user|forum|board)#(?<kid3>\d+)|(?<kind4>attachment|document|version|news|message|project|user|forum|board):(?:"(?<quoted4>[^"]+)"|(?<bare4>[^\s<>\]]+))|(?<![\w&])#(?<issue>\d+)(?:#note-(?<notea>\d+)|-(?<noteb>\d+))?(?!\w)|(?<![\w])@(?<login>[A-Za-z0-9_\-]+)|(?<![\w])(?<tracker>[A-Za-z][A-Za-z0-9_-]*)#(?<tid>\d+)/u';

    /**
     * @var list<string>
     */
    private const BLOCK_MACROS = ['toc', 'child_pages', 'include', 'collapse', 'macro_list', 'recent_pages'];

    /**
     * @var list<string>
     */
    private const IMAGE_EXTENSIONS = ['avif', 'bmp', 'gif', 'jpg', 'jpe', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly SettingValue $settings,
        private readonly TextileFormatter $textile,
        private readonly CommonMarkFormatter $commonMark,
        private readonly PlainFormatter $plain,
        private readonly SyntaxHighlight $syntax,
        private readonly LinkCatalog $links,
    ) {}

    public function toHtml(string $text, ?FormattingContext $context = null, ?string $format = null): string
    {
        return $this->render($text, $context ?? new FormattingContext, $this->normalize($format), 0);
    }

    public function normalize(?string $format): string
    {
        $value = $format ?? $this->settings->textFormatting();

        return match ($value) {
            'textile' => 'textile',
            'common_mark' => 'common_mark',
            default => '',
        };
    }

    private function render(string $text, FormattingContext $context, string $format, int $depth): string
    {
        if ($depth > 5) {
            return $this->macroError('include', 'too deep');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            return '';
        }
        [$text, $codes] = $this->extractCode($text);
        [$text, $macros] = $this->extractMacros($text);
        [$text, $links] = $this->extractLinks($text);
        $html = match ($format) {
            'textile' => $this->textile->convert($text),
            'common_mark' => $this->commonMark->convert($text),
            default => $this->plain->convert($text),
        };
        $html = $this->restoreCode($html, $codes);
        $fragment = HtmlFragment::fromHtml($html);
        $fragment->sanitize();
        $this->highlight($fragment->root(), $fragment->document());
        $this->anchors($fragment->root(), $fragment->document(), $context);
        $this->restoreLinkTokens($fragment->root(), $links);
        $this->rewriteLinks($fragment->root(), $fragment->document(), $context);
        $this->rewriteImages($fragment->root(), $context);
        $this->expandMacros($fragment->root(), $fragment->document(), $macros, $context, $format, $depth);

        return $fragment->html();
    }

    /**
     * @return array{0: string, 1: array<int, array{language: string, code: string}>}
     */
    private function extractCode(string $text): array
    {
        $codes = [];
        $index = 0;
        $stripped = preg_replace_callback(
            '/<pre>\s*<code(?:\s+class="([^"]*)")?\s*>(.*?)<\/code>\s*<\/pre>/si',
            function (array $match) use (&$codes, &$index): string {
                $language = $this->syntax->language($match[1]) ?? 'text';
                $codes[$index] = ['language' => $language, 'code' => $match[2]];
                $token = 'XCODE'.$index.'END';
                $index++;

                return $token;
            },
            $text,
        );

        return [is_string($stripped) ? $stripped : $text, $codes];
    }

    /**
     * @param  array<int, array{language: string, code: string}>  $codes
     */
    private function restoreCode(string $html, array $codes): string
    {
        foreach ($codes as $index => $code) {
            $inner = $this->syntax->highlight($code['code'], $code['language']);
            $replacement = '<pre><code class="'.$code['language'].' syntaxhl">'.$inner.'</code></pre>';
            $token = 'XCODE'.$index.'END';
            $html = str_replace('<p>'.$token.'</p>', $replacement, $html);
            $html = str_replace($token, $replacement, $html);
        }

        return $html;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function extractLinks(string $text): array
    {
        $links = [];
        $index = 0;
        $stripped = preg_replace_callback(
            self::PATTERN,
            function (array $match) use (&$links, &$index): string {
                $links[$index] = $match[0];
                $token = 'XLINK'.$index.'END';
                $index++;

                return $token;
            },
            $text,
        );

        return [is_string($stripped) ? $stripped : $text, $links];
    }

    /**
     * @param  array<int, string>  $links
     */
    private function restoreLinkTokens(DOMElement $root, array $links): void
    {
        if ($links === []) {
            return;
        }
        $texts = [];
        $this->collectText($root, $texts);
        foreach ($texts as $text) {
            if (! str_contains($text->data, 'XLINK')) {
                continue;
            }
            $value = $text->data;
            foreach ($links as $index => $original) {
                $value = str_replace('XLINK'.$index.'END', $original, $value);
            }
            $text->data = $value;
        }
    }

    /**
     * @return array{0: string, 1: array<int, array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}>}
     */
    private function extractMacros(string $text): array
    {
        $macros = [];
        $index = 0;
        $stripped = preg_replace_callback(
            '/\{\{([a-zA-Z0-9_]+)(?:\(([^)]*)\))?[ \t]*\n(.*?)\n\}\}/s',
            function (array $match) use (&$macros, &$index): string {
                return $this->storeMacro($macros, $index, $match[0], strtolower($match[1]), '', $match[2], $match[3]);
            },
            $text,
        );
        $text = is_string($stripped) ? $stripped : $text;
        $stripped = preg_replace_callback(
            '/\{\{([<>]?)([a-zA-Z0-9_]+)(?:\(([^)]*)\))?\}\}/',
            function (array $match) use (&$macros, &$index): string {
                $align = $match[1] === '>' ? 'right' : 'left';

                return $this->storeMacro($macros, $index, $match[0], strtolower($match[2]), $align, $match[3] ?? '', null);
            },
            $text,
        );

        return [is_string($stripped) ? $stripped : $text, $macros];
    }

    /**
     * @param  array<int, array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}>  $macros
     */
    private function storeMacro(array &$macros, int &$index, string $original, string $name, string $align, string $args, ?string $body): string
    {
        $parsed = $this->splitArgs($args);
        $macros[$index] = [
            'name' => $name,
            'align' => $align === '' ? 'left' : $align,
            'positional' => $parsed['positional'],
            'named' => $parsed['named'],
            'body' => $body,
            'original' => $original,
        ];
        $token = 'XMACRO'.$index.'END';
        $index++;

        return $token;
    }

    /**
     * @return array{positional: list<string>, named: array<string, string>}
     */
    private function splitArgs(string $raw): array
    {
        $positional = [];
        $named = [];
        if (trim($raw) === '') {
            return ['positional' => [], 'named' => []];
        }
        $parts = str_getcsv($raw, ',', '"', '\\');
        foreach ($parts as $part) {
            if (! is_string($part)) {
                continue;
            }
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $part, $match) === 1) {
                $named[$match[1]] = trim($match[2], " \t\"");

                continue;
            }
            $positional[] = $part;
        }

        return ['positional' => $positional, 'named' => $named];
    }

    private function highlight(DOMElement $root, DOMDocument $document): void
    {
        $codes = [];
        foreach ($root->getElementsByTagName('code') as $code) {
            $codes[] = $code;
        }
        foreach ($codes as $code) {
            $parent = $code->parentNode;
            if (! $parent instanceof DOMElement || strtolower($parent->nodeName) !== 'pre') {
                continue;
            }
            $language = $this->syntax->language($code->getAttribute('class'));
            if ($language === null) {
                continue;
            }
            $raw = $code->textContent ?? '';
            while ($code->firstChild instanceof DOMNode) {
                $code->removeChild($code->firstChild);
            }
            $this->appendHtml($document, $code, $this->syntax->highlight($raw, $language));
            $code->setAttribute('class', $language.' syntaxhl');
        }
    }

    private function anchors(DOMElement $root, DOMDocument $document, FormattingContext $context): void
    {
        if (! $context->headingAnchors) {
            return;
        }
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $root);
        if ($nodes === false) {
            return;
        }
        $used = [];
        $section = 0;
        $headings = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $headings[] = $node;
            }
        }
        foreach ($headings as $heading) {
            $section++;
            $anchor = $this->uniqueAnchor($this->headingLabel($heading), $used);
            $heading->setAttribute('id', $anchor);
            $link = $document->createElement('a');
            $link->setAttribute('class', 'wiki-anchor');
            $link->setAttribute('href', '#'.$anchor);
            $link->appendChild($document->createTextNode('¶'));
            $heading->appendChild($link);
            if (! $context->sectionEdit || ! $heading->parentNode instanceof DOMNode) {
                continue;
            }
            $edit = $document->createElement('a');
            $edit->setAttribute('class', 'wiki-edit-section icon-only icon-edit');
            $edit->setAttribute('title', 'Edit this section');
            $edit->setAttribute('href', $this->sectionHref($context, $section));
            $edit->appendChild($document->createTextNode('Edit'));
            $heading->parentNode->insertBefore($edit, $heading);
        }
    }

    /**
     * @param  array<string, true>  $used
     */
    private function uniqueAnchor(string $text, array &$used): string
    {
        $slug = preg_replace('/\s+/u', '-', trim($text)) ?? trim($text);
        $slug = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $slug) ?? $slug;
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
        if ($slug === '') {
            $slug = 'section';
        }
        $base = $slug;
        $number = 2;
        while (isset($used[$slug])) {
            $slug = $base.'-'.$number;
            $number++;
        }
        $used[$slug] = true;

        return $slug;
    }

    private function headingLabel(DOMElement $heading): string
    {
        $text = '';
        foreach ($heading->childNodes as $child) {
            if ($child instanceof DOMElement && str_contains($child->getAttribute('class'), 'wiki-anchor')) {
                continue;
            }
            $text .= $child->textContent ?? '';
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function sectionHref(FormattingContext $context, int $section): string
    {
        $page = $this->links->currentWikiPage($context);
        $project = $context->project ?? ($page instanceof WikiPage ? $this->links->wikiProject($page) : null);
        if (! $page instanceof WikiPage || ! $project instanceof Project) {
            return '#section-'.$section;
        }

        return $this->links->url($this->links->wikiPath($project, (string) $page->title).'/edit?section='.$section, $context);
    }

    private function rewriteLinks(DOMElement $root, DOMDocument $document, FormattingContext $context): void
    {
        $texts = [];
        $this->collectText($root, $texts);
        foreach ($texts as $text) {
            if (! $text->parentNode instanceof DOMNode || $this->insideSkipped($text)) {
                continue;
            }
            $this->replaceLinkText($text, $document, $context);
        }
    }

    /**
     * @param  list<DOMText>  $texts
     */
    private function collectText(DOMNode $node, array &$texts): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $texts[] = $child;

                continue;
            }
            if ($child instanceof DOMElement) {
                $this->collectText($child, $texts);
            }
        }
    }

    private function insideSkipped(DOMText $text): bool
    {
        $parent = $text->parentNode;
        while ($parent instanceof DOMElement) {
            $name = strtolower($parent->nodeName);
            if (in_array($name, ['a', 'pre', 'code'], true)) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    private function replaceLinkText(DOMText $text, DOMDocument $document, FormattingContext $context): void
    {
        $value = $text->data;
        if (preg_match_all(self::PATTERN, $value, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== 1) {
            if ($matches === []) {
                return;
            }
        }
        if ($matches === []) {
            return;
        }
        $parent = $text->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }
        $cursor = 0;
        foreach ($matches as $match) {
            $full = $match[0][0];
            $offset = $match[0][1];
            if ($offset < $cursor) {
                continue;
            }
            $escaped = $offset > 0 && $value[$offset - 1] === '!';
            $plainEnd = $escaped ? $offset - 1 : $offset;
            if ($plainEnd > $cursor) {
                $parent->insertBefore($document->createTextNode(substr($value, $cursor, $plainEnd - $cursor)), $text);
            }
            if ($escaped) {
                $parent->insertBefore($document->createTextNode($full), $text);
            } else {
                $linked = $this->linkElement($document, $this->captures($match), $context);
                $parent->insertBefore($linked ?? $document->createTextNode($full), $text);
            }
            $cursor = $offset + strlen($full);
        }
        if ($cursor < strlen($value)) {
            $parent->insertBefore($document->createTextNode(substr($value, $cursor)), $text);
        }
        $parent->removeChild($text);
    }

    /**
     * @param  array<int|string, mixed>  $match
     * @return array<string, array{0: string, 1: int}>
     */
    private function captures(array $match): array
    {
        $captures = [];
        foreach ($match as $key => $value) {
            if (! is_array($value) || ! isset($value[0], $value[1]) || ! is_string($value[0]) || ! is_int($value[1])) {
                continue;
            }
            $name = is_int($key) && $key === 0 ? 'full' : (string) $key;
            $captures[$name] = [$value[0], $value[1]];
        }

        return $captures;
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $match
     */
    private function linkElement(DOMDocument $document, array $match, FormattingContext $context): ?DOMElement
    {
        if (! isset($match['full'])) {
            return null;
        }
        $full = $match['full'][0];
        if (isset($match['wiki']) && $match['wiki'][1] >= 0) {
            return $this->wikiLink($document, $match['wiki'][0], $context);
        }
        $fullIssue = $this->named($match, 'fullissue');
        if ($fullIssue !== null) {
            return $this->issueSummaryLink($document, (int) $fullIssue, $context);
        }
        $sameNote = $this->named($match, 'samenote');
        if ($sameNote !== null) {
            return $this->sameIssueNote($document, $sameNote, $full, $context);
        }
        $kind = $this->named($match, 'kind')
            ?? $this->named($match, 'kind2')
            ?? $this->named($match, 'kind3')
            ?? $this->named($match, 'kind4');
        if ($kind !== null) {
            $value = $this->named($match, 'kid')
                ?? $this->named($match, 'kid3')
                ?? $this->named($match, 'quoted')
                ?? $this->named($match, 'quoted4')
                ?? $this->trimBare($this->named($match, 'bare'))
                ?? $this->trimBare($this->named($match, 'bare4'));
            $projectName = $this->named($match, 'proj') ?? $this->named($match, 'proj2');
            $scoped = null;
            if ($projectName !== null) {
                $scoped = $this->links->projectByIdentifier($projectName);
                if (! $scoped instanceof Project) {
                    return null;
                }
            }

            return $value === null ? null : $this->prefixedLink($document, $kind, $value, $context, $scoped, $projectName !== null);
        }
        $issueId = $this->named($match, 'issue');
        if ($issueId !== null) {
            $note = $this->named($match, 'notea') ?? $this->named($match, 'noteb');

            return $this->hashIssue($document, (int) $issueId, $note, $full, $context);
        }
        $login = $this->named($match, 'login');
        if ($login !== null) {
            return $this->userLink($document, $this->links->userByLogin($login), $context);
        }
        $trackerName = $this->named($match, 'tracker');
        $trackerId = $this->named($match, 'tid');
        if ($trackerName !== null && $trackerId !== null && $this->links->tracker($trackerName) !== null) {
            return $this->hashIssue($document, (int) $trackerId, null, $full, $context);
        }

        return null;
    }

    /**
     * @param  array<string, array{0: string, 1: int}>  $match
     */
    private function named(array $match, string $name): ?string
    {
        if (! isset($match[$name])) {
            return null;
        }
        if ($match[$name][1] < 0 || $match[$name][0] === '') {
            return null;
        }

        return $match[$name][0];
    }

    private function trimBare(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = rtrim($value, '.,;:');

        return $trimmed === '' ? null : $trimmed;
    }

    private function hashIssue(DOMDocument $document, int $id, ?string $note, string $label, FormattingContext $context): ?DOMElement
    {
        $issue = $this->links->issue($id, $context->viewer);
        if (! $issue instanceof Issue) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/issues/'.$issue->id.($note !== null ? '#note-'.$note : ''), $context),
            implode(' ', $this->links->issueClasses($issue, $context->viewer)),
            $this->links->issueTitle($issue),
            $label,
        );
    }

    private function issueSummaryLink(DOMDocument $document, int $id, FormattingContext $context): ?DOMElement
    {
        $issue = $this->links->issue($id, $context->viewer);
        if (! $issue instanceof Issue) {
            return null;
        }

        return $this->hashIssue($document, $id, null, $this->issueMacroLabel($issue, false, true, true), $context);
    }

    private function sameIssueNote(DOMDocument $document, string $note, string $label, FormattingContext $context): ?DOMElement
    {
        $issue = $this->contextIssue($context);
        if (! $issue instanceof Issue) {
            return null;
        }

        return $this->hashIssue($document, (int) $issue->id, $note, $label, $context);
    }

    private function contextIssue(FormattingContext $context): ?Issue
    {
        $object = $context->object;
        if ($object instanceof Issue) {
            return $object;
        }
        if ($object instanceof Journal && (string) $object->journalized_type === 'Issue') {
            return $this->links->issue((int) $object->journalized_id, $context->viewer);
        }

        return null;
    }

    private function wikiLink(DOMDocument $document, string $inner, FormattingContext $context): ?DOMElement
    {
        $label = null;
        $target = $inner;
        $pipe = strpos($inner, '|');
        if ($pipe !== false) {
            $target = substr($inner, 0, $pipe);
            $label = substr($inner, $pipe + 1);
        }
        $anchorName = null;
        $hash = strpos($target, '#');
        if ($hash !== false) {
            $anchorName = substr($target, $hash + 1);
            $target = substr($target, 0, $hash);
        }
        $project = $context->project;
        $colon = strpos($target, ':');
        if ($colon !== false) {
            $maybe = $this->links->projectByIdentifier(substr($target, 0, $colon));
            if ($maybe instanceof Project) {
                $project = $maybe;
                $target = substr($target, $colon + 1);
            }
        }
        if (! $project instanceof Project) {
            return null;
        }
        $anchorOnly = trim($target) === '' && $anchorName !== null && $anchorName !== '';
        if (trim($target) === '') {
            $current = $this->links->currentWikiPage($context);
            $title = $anchorOnly && $current instanceof WikiPage
                ? (string) $current->title
                : $this->links->wikiStartTitle($project);
        } else {
            $title = trim(str_replace('_', ' ', $target));
        }
        $page = $this->links->wikiPage($project, $title);
        $class = 'wiki-page'.($page instanceof WikiPage ? '' : ' new');
        $href = $this->links->url($this->links->wikiPath($project, $title, $anchorName), $context);
        $display = $label ?? ($anchorOnly ? '#'.$anchorName : $title);

        return $this->anchor($document, $href, $class, null, $display);
    }

    private function prefixedLink(DOMDocument $document, string $kind, string $value, FormattingContext $context, ?Project $scoped = null, bool $forcedProject = false): ?DOMElement
    {
        $project = $forcedProject ? $scoped : $context->project;

        return match ($kind) {
            'attachment' => $this->attachmentLink($document, $context, $value),
            'version' => $this->versionLink($document, $this->links->version($value, $project), $context),
            'document' => $this->documentLink($document, $this->links->document($value, $project), $context),
            'news' => $this->newsLink($document, $this->links->news($value, $project), $context),
            'message' => $this->messageLink($document, ctype_digit($value) ? $this->links->message((int) $value) : null, $context),
            'project' => $this->projectLink($document, $this->projectTarget($value), $context),
            'user' => $this->userLink($document, ctype_digit($value) ? $this->links->userById((int) $value) : $this->links->userByLogin($value), $context),
            'forum', 'board' => $this->boardLink($document, $this->boardTarget($value, $project), $context),
            default => null,
        };
    }

    private function boardTarget(string $value, ?Project $project): ?Board
    {
        if (ctype_digit($value)) {
            return $this->links->board((int) $value);
        }

        return $this->links->boardByName($value, $project);
    }

    private function projectTarget(string $value): ?Project
    {
        if (ctype_digit($value)) {
            return $this->links->projectById((int) $value);
        }
        $byIdentifier = $this->links->projectByIdentifier($value);

        return $byIdentifier instanceof Project ? $byIdentifier : $this->links->projectByName($value);
    }

    private function attachmentLink(DOMDocument $document, FormattingContext $context, string $filename): ?DOMElement
    {
        $attachment = $this->links->attachment($context, $filename);
        if (! $attachment instanceof Attachment) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url($this->links->attachmentPath($attachment), $context),
            'attachment',
            null,
            (string) $attachment->filename,
        );
    }

    private function versionLink(DOMDocument $document, ?Version $version, FormattingContext $context): ?DOMElement
    {
        if (! $version instanceof Version) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/versions/'.(int) $version->id, $context),
            null,
            null,
            (string) $version->name,
        );
    }

    private function documentLink(DOMDocument $document, ?Document $record, FormattingContext $context): ?DOMElement
    {
        if (! $record instanceof Document) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/documents/'.(int) $record->id, $context),
            null,
            null,
            (string) $record->title,
        );
    }

    private function newsLink(DOMDocument $document, ?News $news, FormattingContext $context): ?DOMElement
    {
        if (! $news instanceof News) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/news/'.(int) $news->id, $context),
            null,
            null,
            (string) $news->title,
        );
    }

    private function messageLink(DOMDocument $document, ?Message $message, FormattingContext $context): ?DOMElement
    {
        if (! $message instanceof Message) {
            return null;
        }
        $boardId = (int) $message->board_id;
        if ($message->parent_id === null) {
            $href = '/boards/'.$boardId.'/topics/'.(int) $message->id;
        } else {
            $href = '/boards/'.$boardId.'/topics/'.(int) $message->parent_id.'#message-'.(int) $message->id;
        }

        return $this->anchor($document, $this->links->url($href, $context), null, null, (string) $message->subject);
    }

    private function projectLink(DOMDocument $document, ?Project $project, FormattingContext $context): ?DOMElement
    {
        if (! $project instanceof Project) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/projects/'.rawurlencode((string) $project->identifier), $context),
            null,
            null,
            (string) $project->name,
        );
    }

    private function userLink(DOMDocument $document, ?User $user, FormattingContext $context): ?DOMElement
    {
        if (! $user instanceof User) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/users/'.(int) $user->id, $context),
            $this->links->userClass($user),
            null,
            $this->links->personName($user),
        );
    }

    private function boardLink(DOMDocument $document, ?Board $board, FormattingContext $context): ?DOMElement
    {
        if (! $board instanceof Board) {
            return null;
        }
        $project = $board->project;
        if (! $project instanceof Project) {
            $project = $this->links->projectById((int) $board->project_id);
        }
        if (! $project instanceof Project) {
            return null;
        }

        return $this->anchor(
            $document,
            $this->links->url('/projects/'.rawurlencode((string) $project->identifier).'/boards/'.(int) $board->id, $context),
            null,
            null,
            (string) $board->name,
        );
    }

    private function anchor(DOMDocument $document, string $href, ?string $class, ?string $title, string $label): DOMElement
    {
        $link = $document->createElement('a');
        $link->setAttribute('href', $href);
        if ($class !== null && $class !== '') {
            $link->setAttribute('class', $class);
        }
        if ($title !== null && $title !== '') {
            $link->setAttribute('title', $title);
        }
        $link->appendChild($document->createTextNode($label));

        return $link;
    }

    private function rewriteImages(DOMElement $root, FormattingContext $context): void
    {
        $images = [];
        foreach ($root->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }
        foreach ($images as $image) {
            $source = $image->getAttribute('src');
            $name = str_starts_with($source, 'attachment:') ? substr($source, strlen('attachment:')) : $source;
            if ($name === '' || str_contains($name, '://') || str_starts_with($name, '/') || str_starts_with($name, '#')) {
                continue;
            }
            $attachment = $this->links->attachment($context, rawurldecode($name));
            if (! $attachment instanceof Attachment) {
                continue;
            }
            $image->setAttribute('src', $this->links->url($this->links->attachmentPath($attachment), $context));
            if ($image->getAttribute('alt') === '' || $image->getAttribute('alt') === $name) {
                $image->setAttribute('alt', (string) $attachment->filename);
            }
        }
    }

    /**
     * @param  array<int, array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}>  $macros
     */
    private function expandMacros(DOMElement $root, DOMDocument $document, array $macros, FormattingContext $context, string $format, int $depth): void
    {
        if ($macros === []) {
            return;
        }
        $texts = [];
        $this->collectText($root, $texts);
        foreach ($texts as $text) {
            if (! $text->parentNode instanceof DOMNode || ! str_contains($text->data, 'XMACRO')) {
                continue;
            }
            $this->replaceMacroText($root, $text, $document, $macros, $context, $format, $depth);
        }
    }

    /**
     * @param  array<int, array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}>  $macros
     */
    private function replaceMacroText(DOMElement $root, DOMText $text, DOMDocument $document, array $macros, FormattingContext $context, string $format, int $depth): void
    {
        $value = $text->data;
        if (preg_match_all('/XMACRO(\d+)END/', $value, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return;
        }
        $parent = $text->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }
        $only = trim($value);
        if (count($matches) === 1 && $only === $matches[0][0][0] && $parent instanceof DOMElement && strtolower($parent->nodeName) === 'p') {
            $index = (int) $matches[0][1][0];
            $macro = $macros[$index] ?? null;
            $container = $parent->parentNode;
            if ($macro !== null && in_array($macro['name'], self::BLOCK_MACROS, true) && $container instanceof DOMNode) {
                $html = $this->macroHtml($macro, $context, $format, $depth, $root);
                $this->insertHtmlBefore($document, $container, $parent, $html);
                $container->removeChild($parent);

                return;
            }
        }
        $cursor = 0;
        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($offset > $cursor) {
                $parent->insertBefore($document->createTextNode(substr($value, $cursor, $offset - $cursor)), $text);
            }
            $index = (int) $match[1][0];
            $macro = $macros[$index] ?? null;
            $html = $macro === null ? '' : $this->macroHtml($macro, $context, $format, $depth, $root);
            if ($html === '') {
                $parent->insertBefore($document->createTextNode($match[0][0]), $text);
            } else {
                $this->insertHtmlBefore($document, $parent, $text, $html);
            }
            $cursor = $offset + strlen($match[0][0]);
        }
        if ($cursor < strlen($value)) {
            $parent->insertBefore($document->createTextNode(substr($value, $cursor)), $text);
        }
        $parent->removeChild($text);
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function macroHtml(array $macro, FormattingContext $context, string $format, int $depth, ?DOMNode $scope): string
    {
        if ($macro['body'] !== null && ! in_array($macro['name'], ['hello_world', 'collapse'], true)) {
            return $this->macroError($macro['name'], 'does not accept a block of text');
        }

        return match ($macro['name']) {
            'toc' => $this->toc($macro['align'], $scope),
            'child_pages' => $this->childPages($macro, $context),
            'include' => $this->includePage($macro, $context, $format, $depth),
            'collapse' => $this->collapse($macro, $context, $format, $depth),
            'thumbnail' => $this->thumbnail($macro, $context),
            'hello_world' => $this->helloWorld($macro, $context),
            'macro_list' => $this->macroList(),
            'issue' => $this->issueMacro($macro, $context),
            'recent_pages' => $this->recentPages($macro, $context),
            default => $this->escape($macro['original']),
        };
    }

    private function toc(string $align, ?DOMNode $scope): string
    {
        if (! $scope instanceof DOMElement) {
            return '';
        }
        $document = $scope->ownerDocument;
        if (! $document instanceof DOMDocument) {
            return '';
        }
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $scope);
        if ($nodes === false) {
            return '';
        }
        $items = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $items[] = [
                'level' => (int) substr(strtolower($node->nodeName), 1),
                'id' => $node->getAttribute('id'),
                'text' => $this->headingLabel($node),
            ];
        }
        if ($items === []) {
            return '';
        }
        $side = $align === 'right' ? 'right' : 'left';
        $html = '<ul class="toc '.$side.'">';
        $current = $items[0]['level'];
        foreach ($items as $index => $item) {
            if ($index > 0) {
                if ($item['level'] > $current) {
                    $html .= '<ul>';
                } elseif ($item['level'] < $current) {
                    $html .= str_repeat('</li></ul>', $current - $item['level']).'</li>';
                } else {
                    $html .= '</li>';
                }
            }
            $html .= '<li><a href="#'.$this->escape($item['id']).'">'.$this->escape($item['text']).'</a>';
            $current = $item['level'];
        }
        $html .= str_repeat('</li></ul>', $current - $items[0]['level'] + 1);

        return $html;
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function childPages(array $macro, FormattingContext $context): string
    {
        $page = $this->links->currentWikiPage($context);
        $named = $macro['positional'][0] ?? null;
        if (is_string($named) && $named !== '') {
            $project = $context->project ?? ($page instanceof WikiPage ? $this->links->wikiProject($page) : null);
            $colon = strpos($named, ':');
            if ($colon !== false) {
                $maybe = $this->links->projectByIdentifier(substr($named, 0, $colon));
                if ($maybe instanceof Project) {
                    $project = $maybe;
                    $named = substr($named, $colon + 1);
                }
            }
            $page = $this->links->wikiPage($project, trim($named));
        }
        if (! $page instanceof WikiPage) {
            return $this->macroError('child_pages', 'no wiki page');
        }
        $includeParent = ($macro['named']['parent'] ?? '') === '1';
        $depth = $this->depth($macro['named']['depth'] ?? null);
        $project = $this->links->wikiProject($page) ?? $context->project;
        if ($includeParent) {
            return '<ul class="pages-hierarchy"><li>'.$this->pageAnchor($page, $project, $context).$this->childList($page, $project, $context, $depth).'</li></ul>';
        }
        $list = $this->childList($page, $project, $context, $depth);

        return $list === '' ? '' : $list;
    }

    private function depth(?string $value): ?int
    {
        if ($value === null || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function childList(WikiPage $page, ?Project $project, FormattingContext $context, ?int $depth): string
    {
        if ($depth !== null && $depth < 1) {
            return '';
        }
        $children = $this->links->wikiChildren($page);
        if ($children === []) {
            return '';
        }
        $next = $depth === null ? null : $depth - 1;
        $html = '<ul class="pages-hierarchy">';
        foreach ($children as $child) {
            $html .= '<li>'.$this->pageAnchor($child, $project, $context).$this->childList($child, $project, $context, $next).'</li>';
        }

        return $html.'</ul>';
    }

    private function pageAnchor(WikiPage $page, ?Project $project, FormattingContext $context): string
    {
        if (! $project instanceof Project) {
            return $this->escape((string) $page->title);
        }
        $href = $this->links->url($this->links->wikiPath($project, (string) $page->title), $context);

        return '<a href="'.$this->escape($href).'" class="wiki-page">'.$this->escape((string) $page->title).'</a>';
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function includePage(array $macro, FormattingContext $context, string $format, int $depth): string
    {
        $name = $macro['positional'][0] ?? '';
        if ($name === '') {
            return $this->macroError('include', 'page not found');
        }
        $page = $this->links->currentWikiPage($context);
        $project = $context->project ?? ($page instanceof WikiPage ? $this->links->wikiProject($page) : null);
        $colon = strpos($name, ':');
        if ($colon !== false) {
            $maybe = $this->links->projectByIdentifier(substr($name, 0, $colon));
            if ($maybe instanceof Project) {
                $project = $maybe;
                $name = substr($name, $colon + 1);
            }
        }
        $included = $this->links->wikiPage($project, trim($name));
        if (! $included instanceof WikiPage || ! $project instanceof Project) {
            return $this->macroError('include', 'page not found');
        }
        if (in_array((int) $included->id, $context->includeStack, true)) {
            return $this->macroError('include', 'circular inclusion');
        }
        $content = $this->links->wikiContent($included);
        $text = $content !== null && is_string($content->text) ? $content->text : '';
        $next = $context->forIncludedPage($project, $content ?? $included, (int) $included->id);

        return $this->render($text, $next, $format, $depth + 1);
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function collapse(array $macro, FormattingContext $context, string $format, int $depth): string
    {
        $show = $macro['positional'][0] ?? 'Show';
        $hide = $macro['positional'][1] ?? $show;
        if (! isset($macro['positional'][0])) {
            $hide = 'Hide';
        }
        $body = $macro['body'] ?? '';
        $rendered = trim($body) === '' ? '' : $this->render($body, $context, $format, $depth + 1);
        $id = 'collapse-'.substr(sha1($show."\n".$hide."\n".$body), 0, 8);
        $script = "var s=document.getElementById('".$id."-show');var h=document.getElementById('".$id."-hide');var b=document.getElementById('".$id."');if(s&&h&&b){s.style.display=s.style.display==='none'?'':'none';h.style.display=h.style.display==='none'?'':'none';b.style.display=b.style.display==='none'?'':'none';}return false;";

        return '<a href="#" id="'.$id.'-show" class="icon icon-collapsed collapsible" onclick="'.$script.'">'.$this->escape($show).'</a>'
            .'<a href="#" id="'.$id.'-hide" class="icon icon-expanded collapsible" style="display:none;" onclick="'.$script.'">'.$this->escape($hide).'</a>'
            .'<div id="'.$id.'" class="collapsed-text" style="display:none;">'.$rendered.'</div>';
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function thumbnail(array $macro, FormattingContext $context): string
    {
        $filename = $macro['positional'][0] ?? '';
        $attachment = $this->links->attachment($context, $filename);
        if (! $attachment instanceof Attachment || ! $this->isImage((string) $attachment->filename)) {
            return $this->macroError('thumbnail', 'attachment not found');
        }
        $size = 200;
        $requested = $macro['named']['size'] ?? '';
        if ($requested !== '' && preg_match('/^[1-9]\d*$/', $requested) === 1) {
            $size = (int) $requested;
        }
        $description = is_string($attachment->description) ? trim($attachment->description) : '';
        $title = $macro['named']['title'] ?? ($description !== '' ? $description : (string) $attachment->filename);
        $href = $this->links->url($this->links->attachmentPath($attachment), $context);
        $src = $this->links->url($this->links->thumbnailPath($attachment, $size), $context);

        return '<a href="'.$this->escape($href).'" class="thumbnail" title="'.$this->escape($title).'"><img src="'.$this->escape($src).'" alt="'.$this->escape((string) $attachment->filename).'" /></a>';
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function helloWorld(array $macro, FormattingContext $context): string
    {
        $object = $context->object;
        $name = $object === null ? 'NilClass' : class_basename($object);
        $arguments = $macro['positional'];
        $detail = $arguments === [] ? 'Called with no argument' : 'Arguments: '.implode(', ', $arguments);
        $body = $macro['body'];
        $block = $body === null || $body === ''
            ? 'no block of text.'
            : 'a '.strlen($body).' bytes long block of text.';

        return $this->escape('Hello world! Object: '.$name.', '.$detail.' and '.$block);
    }

    private function macroList(): string
    {
        $html = '<dl>';
        foreach ([
            'hello_world' => 'Sample macro.',
            'macro_list' => 'Lists the available macros.',
            'child_pages' => 'Lists child wiki pages.',
            'recent_pages' => 'Lists recently updated wiki pages.',
            'include' => 'Includes another wiki page.',
            'collapse' => 'Inserts a collapsed block of text.',
            'thumbnail' => 'Displays a clickable thumbnail of an attached image.',
            'issue' => 'Displays an issue link with additional information.',
        ] as $name => $description) {
            $html .= '<dt><code>'.$name.'</code></dt><dd><pre>'.$this->escape($description).'</pre></dd>';
        }

        return $html.'</dl>';
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function issueMacro(array $macro, FormattingContext $context): string
    {
        $id = $macro['positional'][0] ?? '';
        if ($id === '' || preg_match('/^\d+$/', $id) !== 1) {
            return $this->escape('#'.$id);
        }
        $issue = $this->links->issue((int) $id, $context->viewer);
        if (! $issue instanceof Issue) {
            return $this->escape('#'.$id);
        }
        $label = $this->issueMacroLabel(
            $issue,
            ($macro['named']['project'] ?? '') === 'true',
            ($macro['named']['tracker'] ?? '') !== 'false',
            ($macro['named']['subject'] ?? '') !== 'false',
        );
        $href = $this->links->url('/issues/'.(int) $issue->id, $context);
        $class = implode(' ', $this->links->issueClasses($issue, $context->viewer));

        return '<a href="'.$this->escape($href).'" class="'.$this->escape($class).'" title="'.$this->escape($this->links->issueTitle($issue)).'">'.$this->escape($label).'</a>';
    }

    private function issueMacroLabel(Issue $issue, bool $withProject, bool $withTracker, bool $withSubject): string
    {
        $label = '';
        if ($withProject && $issue->project instanceof Project) {
            $label .= (string) $issue->project->name.' - ';
        }
        if ($withTracker) {
            $tracker = $issue->tracker;
            $label .= ($tracker !== null ? (string) $tracker->name : 'Issue').' ';
        }
        $label .= '#'.(int) $issue->id;
        if ($withSubject) {
            $label .= ': '.(string) $issue->subject;
        }

        return $label;
    }

    /**
     * @param  array{name: string, align: string, positional: list<string>, named: array<string, string>, body: ?string, original: string}  $macro
     */
    private function recentPages(array $macro, FormattingContext $context): string
    {
        $project = $context->project;
        $namedProject = $macro['named']['project'] ?? '';
        if ($namedProject !== '') {
            $project = $this->links->projectByIdentifier($namedProject);
        }
        if (! $project instanceof Project) {
            return '';
        }
        $days = 7;
        $requestedDays = $macro['named']['days'] ?? '';
        if ($requestedDays !== '' && preg_match('/^\d+$/', $requestedDays) === 1) {
            $days = (int) $requestedDays;
        }
        $limit = $this->depth($macro['named']['limit'] ?? null);
        $rows = $this->links->recentWikiPages(
            $project,
            $days,
            $limit,
            ($macro['named']['include_subprojects'] ?? '') === 'true',
        );
        if ($rows === []) {
            return '';
        }
        $showTime = ($macro['named']['time'] ?? '') === 'true';
        $html = '<ul>';
        foreach ($rows as $row) {
            $html .= '<li>'.$this->pageAnchor($row['page'], $row['project'], $context);
            if ($showTime) {
                $html .= ' ('.$this->escape($this->ago($row['updated_on'])).')';
            }
            $html .= '</li>';
        }

        return $html.'</ul>';
    }

    private function ago(string $updatedOn): string
    {
        $then = Carbon::parse($updatedOn);
        $seconds = (int) $then->diffInSeconds(Carbon::now(), true);
        if ($seconds < 60) {
            return 'less than a minute ago';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute ago' : $minutes.' minutes ago';
        }
        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return $hours === 1 ? 'about 1 hour ago' : 'about '.$hours.' hours ago';
        }
        $days = intdiv($hours, 24);
        if ($days < 30) {
            return $days === 1 ? '1 day ago' : $days.' days ago';
        }
        $months = intdiv($days, 30);

        return $months === 1 ? 'about 1 month ago' : 'about '.$months.' months ago';
    }

    private function isImage(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    private function macroError(string $name, string $message): string
    {
        return '<span class="error">Error executing the macro '.$this->escape($name).' ('.$this->escape($message).')</span>';
    }

    private function insertHtmlBefore(DOMDocument $document, DOMNode $parent, DOMNode $before, string $html): void
    {
        if ($html === '') {
            return;
        }
        $fragment = HtmlFragment::fromHtml($html);
        $imported = $document->importNode($fragment->root(), true);
        if (! $imported instanceof DOMElement) {
            return;
        }
        while ($imported->firstChild instanceof DOMNode) {
            $parent->insertBefore($imported->firstChild, $before);
        }
    }

    private function appendHtml(DOMDocument $document, DOMElement $element, string $html): void
    {
        $fragment = HtmlFragment::fromHtml($html);
        $imported = $document->importNode($fragment->root(), true);
        if (! $imported instanceof DOMElement) {
            return;
        }
        while ($imported->firstChild instanceof DOMNode) {
            $element->appendChild($imported->firstChild);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
