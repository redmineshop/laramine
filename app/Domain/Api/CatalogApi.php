<?php

namespace App\Domain\Api;

use App\Domain\Acl\PermissionList;
use App\Domain\CustomFields\CustomFieldTypes;
use App\Domain\Queries\QueryVisibility;
use App\Domain\Queries\SavedQueryService;
use App\Http\Api\ApiCall;
use App\Http\Api\ApiPage;
use App\Http\Api\ApiResult;
use App\Models\CustomField;
use App\Models\Enumeration;
use App\Models\IssueStatus;
use App\Models\Project;
use App\Models\Query;
use App\Models\Role;
use App\Models\Tracker;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Read-only catalogs: trackers, statuses, enumerations, custom fields, queries, and roles.
 */
final class CatalogApi
{
    public function __construct(
        private readonly ApiCall $calls,
        private readonly ApiValues $values,
        private readonly SavedQueryService $queries,
    ) {}

    public function trackers(Request $request): ApiResult
    {
        return $this->calls->run(function () use ($request): ApiResult {
            $page = ApiPage::from($request);
            $trackers = Tracker::query()->orderBy('position')->orderBy('id')->get()->all();
            $slice = $page->slice($trackers);
            $rows = [];
            foreach ($slice['rows'] as $tracker) {
                $rows[] = $this->trackerDocument($tracker);
            }

            return ApiResult::ok($this->calls->collection('trackers', $rows, $page, $slice['total']));
        });
    }

    public function showTracker(int $id): ApiResult
    {
        return $this->calls->run(function () use ($id): ApiResult {
            $tracker = Tracker::query()->find($id);
            if (! $tracker instanceof Tracker) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['tracker' => $this->trackerDocument($tracker)]);
        });
    }

    public function statuses(Request $request): ApiResult
    {
        return $this->calls->run(function () use ($request): ApiResult {
            $page = ApiPage::from($request);
            $statuses = IssueStatus::query()->orderBy('position')->orderBy('id')->get()->all();
            $slice = $page->slice($statuses);
            $rows = [];
            foreach ($slice['rows'] as $status) {
                $rows[] = $this->statusDocument($status);
            }

            return ApiResult::ok($this->calls->collection('issue_statuses', $rows, $page, $slice['total']));
        });
    }

    public function showStatus(int $id): ApiResult
    {
        return $this->calls->run(function () use ($id): ApiResult {
            $status = IssueStatus::query()->find($id);
            if (! $status instanceof IssueStatus) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['issue_status' => $this->statusDocument($status)]);
        });
    }

    public function enumerations(string $kind): ApiResult
    {
        return $this->calls->run(function () use ($kind): ApiResult {
            $type = $this->enumerationType($kind);
            if ($type === null) {
                return ApiResult::fail(404, 'Not found');
            }
            $rows = [];
            $items = Enumeration::query()->where('type', $type)->orderBy('position')->orderBy('id')->get();
            foreach ($items as $item) {
                $rows[] = $this->enumerationDocument($item);
            }

            return ApiResult::ok([$kind => $rows]);
        });
    }

    public function customFields(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            if (! $actor->admin || ! $actor->isActive()) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $page = ApiPage::from($request);
            $fields = CustomField::query()->orderBy('position')->orderBy('id')->get()->all();
            $slice = $page->slice($fields);
            $rows = [];
            foreach ($slice['rows'] as $field) {
                $rows[] = $this->customFieldDocument($field, false);
            }

            return ApiResult::ok($this->calls->collection('custom_fields', $rows, $page, $slice['total']));
        });
    }

    public function showCustomField(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            if (! $actor->admin || ! $actor->isActive()) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $field = CustomField::query()->find($id);
            if (! $field instanceof CustomField) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['custom_field' => $this->customFieldDocument($field, true)]);
        });
    }

    public function queries(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $page = ApiPage::from($request);
            $projectId = $request->query('project_id');
            $project = null;
            if (is_numeric($projectId)) {
                $project = Project::query()->find((int) $projectId);
                if (! $project instanceof Project) {
                    return ApiResult::fail(404, 'Not found');
                }
            }
            $queries = $this->queries->visible($actor, $project)->all();
            $slice = $page->slice($queries);
            $rows = [];
            foreach ($slice['rows'] as $query) {
                $rows[] = $this->queryDocument($query);
            }

            return ApiResult::ok($this->calls->collection('queries', $rows, $page, $slice['total']));
        });
    }

    public function showQuery(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $query = Query::query()->find($id);
            if (! $query instanceof Query) {
                return ApiResult::fail(404, 'Not found');
            }
            if (! $this->queries->canView($actor, $query)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }

            return ApiResult::ok(['query' => $this->queryDocument($query)]);
        });
    }

    public function roles(Request $request): ApiResult
    {
        return $this->calls->run(function () use ($request): ApiResult {
            $page = ApiPage::from($request);
            $roles = Role::query()->orderBy('position')->orderBy('id')->get()->all();
            $slice = $page->slice($roles);
            $rows = [];
            foreach ($slice['rows'] as $role) {
                $rows[] = [
                    'id' => (int) $role->id,
                    'name' => (string) $role->name,
                ];
            }

            return ApiResult::ok($this->calls->collection('roles', $rows, $page, $slice['total']));
        });
    }

    public function showRole(int $id): ApiResult
    {
        return $this->calls->run(function () use ($id): ApiResult {
            $role = Role::query()->find($id);
            if (! $role instanceof Role) {
                return ApiResult::fail(404, 'Not found');
            }

            return ApiResult::ok(['role' => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
                'assignable' => (bool) $role->assignable,
                'builtin' => (int) $role->builtin,
                'permissions' => PermissionList::decode($role->getAttributes()['permissions'] ?? null),
            ]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function trackerDocument(Tracker $tracker): array
    {
        $tracker->loadMissing('defaultStatus');
        $status = $tracker->defaultStatus;

        return [
            'id' => (int) $tracker->id,
            'name' => (string) $tracker->name,
            'default_status' => $status instanceof IssueStatus
                ? $this->values->ref((int) $status->id, (string) $status->name)
                : null,
            'description' => $this->values->text($tracker->description),
        ];
    }

    /**
     * @return array{id: int, name: string, is_closed: bool}
     */
    private function statusDocument(IssueStatus $status): array
    {
        return [
            'id' => (int) $status->id,
            'name' => (string) $status->name,
            'is_closed' => (bool) $status->is_closed,
        ];
    }

    /**
     * @return array{id: int, name: string, is_default: bool, active: bool}
     */
    private function enumerationDocument(Enumeration $item): array
    {
        return [
            'id' => (int) $item->id,
            'name' => (string) $item->name,
            'is_default' => (bool) $item->is_default,
            'active' => (bool) $item->active,
        ];
    }

    private function enumerationType(string $kind): ?string
    {
        return match ($kind) {
            'issue_priorities' => 'IssuePriority',
            'time_entry_activities' => 'TimeEntryActivity',
            'document_categories' => 'DocumentCategory',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function customFieldDocument(CustomField $field, bool $detail): array
    {
        $customized = CustomFieldTypes::customizedType((string) $field->type);
        $row = [
            'id' => (int) $field->id,
            'name' => (string) $field->name,
            'customized_type' => $customized === null ? '' : StrSnake::of($customized),
            'field_format' => (string) $field->field_format,
            'regexp' => $this->values->text($field->regexp),
            'min_length' => $field->min_length === null ? null : (int) $field->min_length,
            'max_length' => $field->max_length === null ? null : (int) $field->max_length,
            'is_required' => (bool) $field->is_required,
            'is_filter' => (bool) $field->is_filter,
            'searchable' => (bool) $field->searchable,
            'multiple' => (bool) $field->multiple,
            'default_value' => $field->default_value === null ? null : (string) $field->default_value,
            'visible' => (bool) $field->visible,
        ];
        $possible = $field->possibleValueList();
        $values = [];
        if (is_array($possible)) {
            foreach ($possible as $value) {
                $values[] = ['value' => $value];
            }
        }
        $row['possible_values'] = $values;
        if ($detail) {
            $trackers = [];
            foreach ($field->trackers()->orderBy('trackers.id')->get() as $tracker) {
                $trackers[] = $this->values->ref((int) $tracker->id, (string) $tracker->name);
            }
            $row['trackers'] = $trackers;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryDocument(Query $query): array
    {
        return [
            'id' => (int) $query->id,
            'name' => (string) $query->name,
            'is_public' => (int) $query->visibility === QueryVisibility::PUBLIC,
            'project_id' => $query->project_id === null ? null : (int) $query->project_id,
        ];
    }
}
