<?php

namespace App\Domain\CustomFields\Formats;

use App\Domain\CustomFields\CustomizedContext;
use App\Domain\CustomFields\FieldValues;
use App\Domain\Projects\VersionAvailability;
use App\Models\CustomField;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a version id string. The version must be available on the record's
 * project, including versions shared onto that project. `format_store.version_status`
 * optionally limits status (`open`, `locked`, `closed`).
 */
final class VersionFormat extends AbstractFormat
{
    private const STATUSES = ['open', 'locked', 'closed'];

    public function __construct(
        private readonly CustomizedContext $context,
        private readonly VersionAvailability $versions,
    ) {}

    public function key(): string
    {
        return 'version';
    }

    public function supportsMultiple(): bool
    {
        return true;
    }

    public function supportsSearchable(): bool
    {
        return false;
    }

    public function queryFilterType(): string
    {
        return 'list_optional';
    }

    public function validateDefinition(CustomField $field): array
    {
        $statuses = $this->statuses($field);
        if ($statuses === null) {
            return ['version_status must be open, locked, or closed.'];
        }

        $default = FieldValues::defaultString($field);
        if ($default === null) {
            return [];
        }

        if ($this->canonicalId($default) === null) {
            return ['Default value must be a version id.'];
        }

        return [];
    }

    public function validate(CustomField $field, mixed $raw, ?Model $customized): array
    {
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->parseIds($raw, (bool) $field->multiple);
        if ($parsed['error'] !== null) {
            return [$parsed['error']];
        }
        $ids = $parsed['ids'];

        $statuses = $this->statuses($field) ?? [];
        $project = $customized !== null ? $this->context->project($customized) : null;
        $errors = [];
        $seen = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                $errors[] = 'Value is repeated.';
                break;
            }
            $seen[$id] = true;
            $version = Version::query()->find($id);
            if (! $version instanceof Version) {
                $errors[] = 'Version does not exist.';
                break;
            }
            if ($project !== null && ! $this->versions->available($version, $project)) {
                $errors[] = 'Version is not available for this project.';
                break;
            }
            if ($statuses !== [] && ! in_array((string) $version->status, $statuses, true)) {
                $errors[] = 'Version status is not allowed.';
                break;
            }
        }

        return $errors;
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
        if (FieldValues::isBlank($raw)) {
            return [];
        }

        $parsed = $this->parseIds($raw, (bool) $field->multiple);
        $ids = $parsed['ids'];

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = (string) $id;
        }

        return $rows;
    }

    /**
     * @return list<string>|null
     */
    private function statuses(CustomField $field): ?array
    {
        $store = $field->formatStoreData();
        if (! is_array($store) || ! array_key_exists('version_status', $store)) {
            return [];
        }

        $raw = $store['version_status'];
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $items = is_array($raw) ? $raw : [$raw];
        $statuses = [];
        foreach ($items as $item) {
            if (! is_string($item) || ! in_array($item, self::STATUSES, true)) {
                return null;
            }
            $statuses[] = $item;
        }

        return array_values(array_unique($statuses));
    }

    /**
     * @return array{ids: list<int>, error: ?string}
     */
    private function parseIds(mixed $raw, bool $multiple): array
    {
        $source = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($source as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $id = $this->canonicalId($item);
            if ($id === null) {
                return ['ids' => [], 'error' => 'Value must be a version id.'];
            }
            $ids[] = $id;
        }

        if (! $multiple && count($ids) > 1) {
            return ['ids' => [], 'error' => 'Multiple values are not supported for this format.'];
        }

        return ['ids' => $ids, 'error' => null];
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
