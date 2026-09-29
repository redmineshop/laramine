<?php

namespace App\Models;

use App\Domain\Acl\JsonObjectCast;
use App\Domain\CustomFields\JsonListCast;
use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `custom_fields` row.
 *
 * `type` is the STI name (`IssueCustomField`, …). `possible_values` and
 * `format_store` are JSON text. Non-JSON legacy text decodes as null.
 */
class CustomField extends Model
{
    /** @use HasFactory<CustomFieldFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return list<string>|null
     */
    public function possibleValueList(): ?array
    {
        $value = $this->getAttribute('possible_values');
        if (! is_array($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param  list<string>|null  $values
     */
    public function setPossibleValueList(?array $values): void
    {
        $this->setAttribute('possible_values', $values);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function formatStoreData(): ?array
    {
        $value = $this->getAttribute('format_store');
        if (! is_array($value)) {
            return null;
        }

        $store = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $store[$key] = $item;
            }
        }

        return $store;
    }

    /**
     * @param  array<string, mixed>|null  $store
     */
    public function setFormatStoreData(?array $store): void
    {
        $this->setAttribute('format_store', $store);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'editable' => 'boolean',
            'is_filter' => 'boolean',
            'is_for_all' => 'boolean',
            'is_required' => 'boolean',
            'multiple' => 'boolean',
            'searchable' => 'boolean',
            'visible' => 'boolean',
            'possible_values' => JsonListCast::class,
            'format_store' => JsonObjectCast::class,
        ];
    }

    /**
     * @return HasMany<CustomValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomValue::class);
    }

    /**
     * @return HasMany<CustomFieldEnumeration, $this>
     */
    public function enumerations(): HasMany
    {
        return $this->hasMany(CustomFieldEnumeration::class);
    }

    /**
     * @return BelongsToMany<Tracker, $this>
     */
    public function trackers(): BelongsToMany
    {
        return $this->belongsToMany(Tracker::class, 'custom_fields_trackers', 'custom_field_id', 'tracker_id');
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'custom_fields_projects', 'custom_field_id', 'project_id');
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'custom_fields_roles', 'custom_field_id', 'role_id');
    }
}
