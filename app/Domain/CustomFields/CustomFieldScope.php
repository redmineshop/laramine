<?php

namespace App\Domain\CustomFields;

use App\Models\CustomField;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Which custom fields apply to a record.
 *
 * Issue fields require a tracker link and either `is_for_all` or a project link.
 */
final class CustomFieldScope
{
    public function __construct(private readonly CustomizedContext $context) {}

    /**
     * @return Collection<int, CustomField>
     */
    public function applicable(Model $record): Collection
    {
        $customizedType = $this->context->customizedType($record);
        if ($customizedType === null) {
            return new Collection;
        }

        $sti = CustomFieldTypes::stiFor($customizedType);
        if ($sti === null) {
            return new Collection;
        }

        $query = CustomField::query()
            ->where('type', $sti)
            ->orderBy('position')
            ->orderBy('id')
            ->with('roles');

        if ($record instanceof Issue) {
            $trackerId = (int) $record->tracker_id;
            $projectId = (int) $record->project_id;
            $query->whereHas('trackers', function (Builder $builder) use ($trackerId): void {
                $builder->where('trackers.id', $trackerId);
            });
            $query->where(function (Builder $builder) use ($projectId): void {
                $builder->where('is_for_all', true)
                    ->orWhereHas('projects', function (Builder $projects) use ($projectId): void {
                        $projects->where('projects.id', $projectId);
                    });
            });
        }

        return $query->get();
    }
}
