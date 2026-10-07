<?php

namespace App\Domain\Issues\History;

/**
 * One visible property-diff or relation-add line.
 *
 * HTML marks old and new attribute values with em. Relation-add lines do not.
 */
final readonly class JournalPropertyLine
{
    public function __construct(
        public string $text,
        public string $html,
    ) {}

    public static function changed(string $label, string $old, string $new): self
    {
        return new self(
            $label.' changed from '.$old.' to '.$new,
            self::escape($label).' changed from <em>'.self::escape($old).'</em> to <em>'.self::escape($new).'</em>',
        );
    }

    public static function setTo(string $label, string $value): self
    {
        return new self(
            $label.' set to '.$value,
            self::escape($label).' set to <em>'.self::escape($value).'</em>',
        );
    }

    public static function deleted(string $label, string $old): self
    {
        return new self(
            $label.' deleted ('.$old.')',
            self::escape($label).' deleted (<em>'.self::escape($old).'</em>)',
        );
    }

    public static function updated(string $label): self
    {
        $text = $label.' updated';

        return new self($text, self::escape($text));
    }

    /**
     * A value that was added, such as a file name or a multiple custom-field value.
     *
     * Relation-add lines use relationAdded. emphasizeValue wraps the value in em.
     */
    public static function added(string $label, string $value, bool $emphasizeValue = false): self
    {
        $text = $label.' '.$value.' added';
        if (! $emphasizeValue) {
            return new self($text, self::escape($text));
        }

        return new self(
            $text,
            self::escape($label).' <em>'.self::escape($value).'</em> added',
        );
    }

    public static function relationAdded(string $label, string $trackerName, int $issueId, string $subject): self
    {
        $text = $label.' '.$trackerName.' #'.$issueId.': '.$subject.' added';

        return new self($text, self::escape($text));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
