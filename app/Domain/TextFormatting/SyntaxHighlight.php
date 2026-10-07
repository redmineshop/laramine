<?php

namespace App\Domain\TextFormatting;

/**
 * Wraps a fenced or pre/code block with the syntaxhl class.
 *
 * Ruby tokens use the same span classes Rouge emits for keywords, names,
 * comments, and strings. Other languages stay escaped inside that class.
 */
final class SyntaxHighlight
{
    /**
     * @var list<string>
     */
    private const KEYWORDS = [
        'begin',
        'class',
        'def',
        'do',
        'else',
        'elsif',
        'end',
        'if',
        'module',
        'rescue',
        'return',
        'unless',
        'while',
    ];

    public function language(string $class): ?string
    {
        if (preg_match('/(?:^|\s)(?:language-)?([A-Za-z][A-Za-z0-9_+-]*)/', $class, $match) !== 1) {
            return null;
        }
        $language = strtolower($match[1]);
        if ($language === 'syntaxhl') {
            return null;
        }

        return $language;
    }

    public function highlight(string $code, string $language): string
    {
        if ($language === 'ruby') {
            return $this->ruby($code);
        }

        return $this->escape($code);
    }

    private function ruby(string $code): string
    {
        $html = '';
        $length = strlen($code);
        $index = 0;
        while ($index < $length) {
            $comment = $this->comment($code, $index, $length);
            if ($comment !== null) {
                $html .= $comment['html'];
                $index = $comment['index'];

                continue;
            }
            $string = $this->string($code, $index, $length);
            if ($string !== null) {
                $html .= $string['html'];
                $index = $string['index'];

                continue;
            }
            $named = $this->namedDefinition($code, $index);
            if ($named !== null) {
                $html .= $named['html'];
                $index += $named['length'];

                continue;
            }
            $keyword = $this->keyword($code, $index);
            if ($keyword !== null) {
                $html .= '<span class="k">'.$keyword.'</span>';
                $index += strlen($keyword);

                continue;
            }
            $html .= $this->escape($code[$index]);
            $index++;
        }

        return $html;
    }

    /**
     * @return array{html: string, index: int}|null
     */
    private function comment(string $code, int $index, int $length): ?array
    {
        if ($code[$index] !== '#') {
            return null;
        }
        $end = strpos($code, "\n", $index);
        $end = $end === false ? $length : $end;

        return [
            'html' => '<span class="c1">'.$this->escape(substr($code, $index, $end - $index)).'</span>',
            'index' => $end,
        ];
    }

    /**
     * @return array{html: string, index: int}|null
     */
    private function string(string $code, int $index, int $length): ?array
    {
        $quote = $code[$index];
        if ($quote !== '"' && $quote !== "'") {
            return null;
        }
        $cursor = $index + 1;
        while ($cursor < $length && $code[$cursor] !== $quote) {
            if ($code[$cursor] === '\\' && $cursor + 1 < $length) {
                $cursor += 2;

                continue;
            }
            $cursor++;
        }
        if ($cursor < $length) {
            $cursor++;
        }
        $class = $quote === '"' ? 's2' : 's1';

        return [
            'html' => '<span class="'.$class.'">'.$this->escape(substr($code, $index, $cursor - $index)).'</span>',
            'index' => $cursor,
        ];
    }

    /**
     * @return array{html: string, length: int}|null
     */
    private function namedDefinition(string $code, int $index): ?array
    {
        if (preg_match('/\G(def|class|module)\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $match, 0, $index) !== 1) {
            return null;
        }

        return [
            'html' => '<span class="k">'.$match[1].'</span> <span class="nf">'.$this->escape($match[2]).'</span>',
            'length' => strlen($match[0]),
        ];
    }

    private function keyword(string $code, int $index): ?string
    {
        $list = implode('|', self::KEYWORDS);
        if (preg_match('/\G('.$list.')\b/', $code, $match, 0, $index) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
