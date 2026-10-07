<?php

namespace App\Domain\Issues;

/**
 * Builds the note text stored when one journal quotes another.
 *
 * The author line is the trimmed first and last name, then the login, then
 * the word User. Each line of the source note is prefixed with "> ".
 * A preformatted block is stored as "[...]" so its inner newlines are not
 * quoted line by line.
 */
final class JournalQuoteText
{
    public function authorName(mixed $firstname, mixed $lastname, mixed $login): string
    {
        $name = trim($this->text($firstname).' '.$this->text($lastname));
        if ($name !== '') {
            return $name;
        }

        $fallback = trim($this->text($login));

        return $fallback !== '' ? $fallback : 'User';
    }

    public function render(string $authorName, string $notes): string
    {
        $name = trim($authorName);
        if ($name === '') {
            $name = 'User';
        }

        $body = str_replace(["\r\n", "\r"], "\n", $notes);
        $redacted = preg_replace('/<pre\b[^>]*>.*?<\/pre>/si', '[...]', $body);
        if (! is_string($redacted)) {
            $redacted = $body;
        }
        $redacted = trim($redacted);
        $lines = $redacted === '' ? [''] : explode("\n", $redacted);
        $quoted = [];
        foreach ($lines as $line) {
            $quoted[] = '> '.$line;
        }

        return $name." wrote:\n".implode("\n", $quoted);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
