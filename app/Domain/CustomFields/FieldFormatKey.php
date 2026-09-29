<?php

namespace App\Domain\CustomFields;

/**
 * Registered custom field format keys.
 *
 * Implemented keys validate and store values. Deferred keys are recognized
 * so a definition can be saved, but value writes are rejected.
 */
enum FieldFormatKey: string
{
    case String = 'string';
    case Text = 'text';
    case Link = 'link';
    case Int = 'int';
    case Float = 'float';
    case Date = 'date';
    case List = 'list';
    case Bool = 'bool';
    case Enumeration = 'enumeration';
    case User = 'user';
    case Version = 'version';
    case Attachment = 'attachment';
    case Progressbar = 'progressbar';

    public function implemented(): bool
    {
        return match ($this) {
            self::String, self::Text, self::Int, self::Float, self::Date, self::List, self::Bool, self::User, self::Version => true,
            self::Link, self::Enumeration, self::Attachment, self::Progressbar => false,
        };
    }
}
