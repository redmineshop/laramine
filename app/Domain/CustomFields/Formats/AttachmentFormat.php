<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\CustomizedContext;
use App\Domain\CustomFields\FieldValues;
use App\Models\Attachment;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores an `attachments.id` string.
 *
 * `format_store.extensions_allowed` limits the filename extension when set.
 * A row with no container is accepted. A row that already names a container
 * must name this record. Disk files, digests, and the upload pipeline are not
 * written here.
 */
final class AttachmentFormat extends AbstractFormat
{
    public function __construct(private readonly CustomizedContext $context) {}

    public function key(): string
    {
        return 'attachment';
    }

    public function supportsMultiple(): bool
    {
        return false;
    }

    public function supportsSearchable(): bool
    {
        return false;
    }

    public function queryFilterType(): string
    {
        return 'string';
    }

    public function validateDefinition(CustomField $field): array
    {
        if ($this->extensions($field) === null) {
            return ['extensions_allowed must be a list of extensions.'];
        }

        return parent::validateDefinition($field);
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $multiple = $this->rejectMultiple($raw);
        if ($multiple !== []) {
            return $multiple;
        }

        $extensions = $this->extensions($field);
        if ($extensions === null) {
            return ['extensions_allowed must be a list of extensions.'];
        }

        $id = $this->canonicalId($this->unwrap($raw));
        if ($id === null) {
            return ['Value must be an attachment id.'];
        }

        $attachment = Attachment::query()->find($id);
        if (! $attachment instanceof Attachment) {
            return ['Attachment does not exist.'];
        }

        if ($extensions !== [] && ! in_array($this->extension((string) $attachment->filename), $extensions, true)) {
            return ['Attachment extension is not allowed.'];
        }

        if ($customized !== null && ! $this->containerMatches($attachment, $customized)) {
            return ['Attachment is not attached to this record.'];
        }

        return [];
    }

    public function cast(CustomField $field, ?string $stored): mixed
    {
        unset($field);

        if ($stored === null || $stored === '' || ! ctype_digit($stored)) {
            return null;
        }

        return (int) $stored;
    }

    public function serialize(CustomField $field, mixed $raw): array
    {
        unset($field);

        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $id = $this->canonicalId($this->unwrap($raw));
        if ($id === null) {
            return [];
        }

        return [(string) $id];
    }

    /**
     * Allowed extensions, or null when the store value is not a list of tokens.
     * An empty list means every extension is allowed.
     *
     * @return list<string>|null
     */
    private function extensions(CustomField $field): ?array
    {
        $store = $field->formatStoreData();
        if (! is_array($store) || ! array_key_exists('extensions_allowed', $store)) {
            return [];
        }

        $raw = $store['extensions_allowed'];
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $tokens = [];
        if (is_string($raw)) {
            $split = preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
            if ($split === false) {
                return null;
            }
            $tokens = $split;
        } elseif (is_array($raw)) {
            foreach ($raw as $item) {
                if (! is_string($item)) {
                    return null;
                }
                $tokens[] = $item;
            }
        } else {
            return null;
        }

        $allowed = [];
        foreach ($tokens as $token) {
            $normalized = strtolower(ltrim(trim($token), '.'));
            if ($normalized === '' || preg_match('/^[a-z0-9]+$/', $normalized) !== 1) {
                return null;
            }
            $allowed[] = $normalized;
        }

        return array_values(array_unique($allowed));
    }

    private function extension(string $filename): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === strlen($filename) - 1) {
            return '';
        }

        return strtolower(substr($filename, $dot + 1));
    }

    private function containerMatches(Attachment $attachment, Model $customized): bool
    {
        if ($this->isUnbound($attachment)) {
            return true;
        }

        $type = $this->context->customizedType($customized);
        $id = $customized->getKey();
        if ($type === null || ! is_numeric($id)) {
            return false;
        }

        return (string) $attachment->container_type === $type
            && (int) $attachment->container_id === (int) $id;
    }

    private function isUnbound(Attachment $attachment): bool
    {
        $type = $attachment->container_type;
        $typeBlank = $type === null || $type === '';

        return $typeBlank && $attachment->container_id === null;
    }

    private function canonicalId(mixed $raw): ?int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && preg_match('/^[1-9]\d*$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        return null;
    }
}
