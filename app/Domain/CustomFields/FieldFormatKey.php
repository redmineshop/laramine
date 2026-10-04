<?php

namespace App\Domain\CustomFields;

/**
 * Registered custom field format keys.
 *
 * Every key validates and stores a value. `link`, `enumeration`, `attachment`,
 * and `progressbar` follow the same registry path as the earlier formats.
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
            self::String, self::Text, self::Link, self::Int, self::Float, self::Date, self::List, self::Bool, self::Enumeration, self::User, self::Version, self::Attachment, self::Progressbar => true,
        };
    }
}
