<?php

namespace App\Domain\TextFormatting;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Parses a formatter fragment, drops disallowed tags, and serializes it again.
 *
 * Allowed schemes are http, https, ftp, mailto, and relative or anchor URLs.
 */
final class HtmlFragment
{
    /**
     * @var list<string>
     */
    private const TAGS = [
        'a', 'abbr', 'acronym', 'b', 'blockquote', 'br', 'caption', 'cite', 'code',
        'dd', 'del', 'dfn', 'div', 'dl', 'dt', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'i', 'img', 'input', 'ins', 'kbd', 'li', 'ol', 'p', 'pre', 'q', 's',
        'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'tt', 'u', 'ul', 'var',
    ];

    /**
     * @var list<string>
     */
    private const VOID = ['br', 'hr', 'img', 'input'];

    /**
     * @var list<string>
     */
    private const GLOBAL_ATTRIBUTES = ['class', 'title', 'id', 'name', 'style'];

    /**
     * @var array<string, list<string>>
     */
    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'rel', 'target', 'name'],
        'img' => ['src', 'alt', 'width', 'height', 'title'],
        'td' => ['colspan', 'rowspan', 'align'],
        'th' => ['colspan', 'rowspan', 'align'],
        'ol' => ['start', 'type', 'reversed'],
        'input' => ['type', 'checked', 'disabled'],
    ];

    private function __construct(
        private readonly DOMDocument $document,
        private readonly DOMElement $root,
    ) {}

    public static function fromHtml(string $html): self
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<div id="laramine-root">'.$html.'</div>';
        $flags = LIBXML_HTML_NODEFDTD;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $flags |= LIBXML_HTML_NOIMPLIED;
        }
        $document->loadHTML('<?xml encoding="UTF-8">'.$wrapped, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('laramine-root');
        if (! $root instanceof DOMElement) {
            $root = $document->createElement('div');
            $root->setAttribute('id', 'laramine-root');
            $document->appendChild($root);
        }

        return new self($document, $root);
    }

    public function document(): DOMDocument
    {
        return $this->document;
    }

    public function root(): DOMElement
    {
        return $this->root;
    }

    public function sanitize(): void
    {
        $this->sanitizeChildren($this->root);
    }

    public function html(): string
    {
        $html = '';
        foreach ($this->root->childNodes as $child) {
            $html .= $this->serialize($child);
        }

        return $html;
    }

    private function sanitizeChildren(DOMElement $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if (! $child instanceof DOMElement || $child->parentNode !== $parent) {
                continue;
            }
            $this->sanitizeElement($parent, $child);
        }
    }

    private function sanitizeElement(DOMElement $parent, DOMElement $element): void
    {
        $name = strtolower($element->nodeName);
        if (in_array($name, ['script', 'style'], true)) {
            $parent->removeChild($element);

            return;
        }
        if (! in_array($name, self::TAGS, true)) {
            $this->unwrap($parent, $element);

            return;
        }
        if ($name === 'input' && strtolower($element->getAttribute('type')) !== 'checkbox') {
            $parent->removeChild($element);

            return;
        }
        $this->cleanAttributes($element);
        $this->sanitizeChildren($element);
    }

    private function unwrap(DOMElement $parent, DOMElement $element): void
    {
        while ($element->firstChild instanceof DOMNode) {
            $child = $element->firstChild;
            $parent->insertBefore($child, $element);
            if ($child instanceof DOMElement) {
                $this->sanitizeElement($parent, $child);
            }
        }
        if ($element->parentNode === $parent) {
            $parent->removeChild($element);
        }
    }

    private function cleanAttributes(DOMElement $element): void
    {
        $name = strtolower($element->nodeName);
        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$name] ?? []);
        $remove = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            if (! in_array($attributeName, $allowed, true)) {
                $remove[] = $attribute->nodeName;

                continue;
            }
            if ($attributeName === 'style' && preg_match('/expression|javascript|behavior|url\s*\(\s*[\'"]?\s*javascript/i', $attribute->nodeValue ?? '') === 1) {
                $remove[] = $attribute->nodeName;

                continue;
            }
            if (in_array($attributeName, ['href', 'src'], true) && ! $this->allowedUrl($attribute->nodeValue ?? '')) {
                $remove[] = $attribute->nodeName;
            }
        }
        foreach ($remove as $attributeName) {
            $element->removeAttribute($attributeName);
        }
        if ($name === 'input') {
            $element->setAttribute('type', 'checkbox');
            $element->setAttribute('disabled', 'disabled');
        }
    }

    private function allowedUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '#') || str_starts_with($value, '/') || str_starts_with($value, '?') || str_starts_with($value, '.')) {
            return true;
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $value, $match) !== 1) {
            return true;
        }

        return in_array(strtolower($match[1]), ['http', 'https', 'ftp', 'mailto'], true);
    }

    private function serialize(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        $name = strtolower($node->nodeName);
        $attributes = $this->attributeString($node);
        if (in_array($name, self::VOID, true)) {
            return '<'.$name.$attributes.' />';
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->serialize($child);
        }

        return '<'.$name.$attributes.'>'.$inner.'</'.$name.'>';
    }

    private function attributeString(DOMElement $element): string
    {
        $values = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $values[strtolower($attribute->nodeName)] = $attribute->nodeValue ?? '';
        }
        $order = ['href', 'src', 'type', 'checked', 'disabled', 'class', 'title', 'alt', 'id', 'name', 'width', 'height', 'style', 'rel', 'target', 'colspan', 'rowspan', 'align', 'start', 'reversed'];
        $names = array_keys($values);
        usort($names, function (string $left, string $right) use ($order): int {
            $leftRank = array_search($left, $order, true);
            $rightRank = array_search($right, $order, true);
            $leftRank = $leftRank === false ? 100 : $leftRank;
            $rightRank = $rightRank === false ? 100 : $rightRank;
            if ($leftRank === $rightRank) {
                return $left <=> $right;
            }

            return $leftRank <=> $rightRank;
        });
        $html = '';
        foreach ($names as $name) {
            if ($name === 'id' && $element->getAttribute('id') === 'laramine-root') {
                continue;
            }
            $html .= ' '.$name.'="'.htmlspecialchars($values[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }

        return $html;
    }
}
