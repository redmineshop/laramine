<?php

namespace App\Domain\CustomFields;

use App\Domain\Acl\MembershipService;
use App\Domain\CustomFields\Formats\AttachmentFormat;
use App\Domain\CustomFields\Formats\BoolFormat;
use App\Domain\CustomFields\Formats\DateFormat;
use App\Domain\CustomFields\Formats\EnumerationFormat;
use App\Domain\CustomFields\Formats\FloatFormat;
use App\Domain\CustomFields\Formats\IntFormat;
use App\Domain\CustomFields\Formats\LinkFormat;
use App\Domain\CustomFields\Formats\ListFormat;
use App\Domain\CustomFields\Formats\ProgressbarFormat;
use App\Domain\CustomFields\Formats\StringFormat;
use App\Domain\CustomFields\Formats\TextFormat;
use App\Domain\CustomFields\Formats\UserFormat;
use App\Domain\CustomFields\Formats\VersionFormat;
use App\Domain\DomainException;

/**
 * Maps `field_format` to a format handler. All 13 Redmine 7.0.1 keys resolve.
 */
final class FieldFormatRegistry
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly CustomizedContext $context,
    ) {}

    public function get(string $format): FieldFormat
    {
        $key = FieldFormatKey::tryFrom($format);
        if ($key === null) {
            throw new DomainException('Unknown custom field format: '.$format);
        }

        return match ($key) {
            FieldFormatKey::String => new StringFormat,
            FieldFormatKey::Text => new TextFormat,
            FieldFormatKey::Link => new LinkFormat,
            FieldFormatKey::Int => new IntFormat,
            FieldFormatKey::Float => new FloatFormat,
            FieldFormatKey::Date => new DateFormat,
            FieldFormatKey::List => new ListFormat,
            FieldFormatKey::Bool => new BoolFormat,
            FieldFormatKey::Enumeration => new EnumerationFormat,
            FieldFormatKey::User => new UserFormat($this->memberships, $this->context),
            FieldFormatKey::Version => new VersionFormat($this->context),
            FieldFormatKey::Attachment => new AttachmentFormat($this->context),
            FieldFormatKey::Progressbar => new ProgressbarFormat,
        };
    }

    /**
     * @return list<FieldFormat>
     */
    public function all(): array
    {
        $formats = [];
        foreach (FieldFormatKey::cases() as $case) {
            $formats[] = $this->get($case->value);
        }

        return $formats;
    }
}
