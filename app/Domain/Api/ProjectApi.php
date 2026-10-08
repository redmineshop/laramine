<?php

namespace App\Domain\Api;

use App\Domain\Acl\ManagedRoleGuard;
use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\Projects\ProjectService;
use App\Domain\Tree\NestedSet;
use App\Http\Api\ApiCall;
use App\Http\Api\ApiPage;
use App\Http\Api\ApiQuery;
use App\Http\Api\ApiResult;
use App\Models\EnabledModule;
use App\Models\Enumeration;
use App\Models\Issue;
use App\Models\IssueCategory;
use App\Models\Member;
use App\Models\MemberRole;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Projects, memberships, versions, and issue categories for the REST API.
 */
final class ProjectApi
{
    public function __construct(
        private readonly ApiCall $calls,
        private readonly ApiValues $values,
        private readonly PermissionService $permissions,
        private readonly ProjectService $projects,
        private readonly MembershipService $memberships,
        private readonly ManagedRoleGuard $managedRoles,
    ) {}

    public function index(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $page = ApiPage::from($request);
            $includes = ApiQuery::includes($request);
            $visible = $this->visibleProjects($actor);
            $slice = $page->slice($visible);
            $rows = [];
            foreach ($slice['rows'] as $project) {
                $rows[] = $this->document($actor, $project, $includes, false);
            }

            return ApiResult::ok($this->calls->collection('projects', $rows, $page, $slice['total']));
        });
    }

    public function show(User $actor, string $key, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key, $request): ApiResult {
            $project = $this->visible($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }

            return ApiResult::ok([
                'project' => $this->document($actor, $project, ApiQuery::includes($request), true),
            ]);
        });
    }

    public function store(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            if (! $this->permissions->allowed($actor, 'add_project')) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $attributes = ApiQuery::resource($request, 'project');
            $parent = null;
            if (isset($attributes['parent_id']) && $attributes['parent_id'] !== '') {
                $found = $this->visible($actor, (string) $attributes['parent_id']);
                if ($found instanceof ApiResult) {
                    return $found;
                }
                $parent = $found;
            }
            $project = $this->projects->create([
                'name' => $this->values->text($attributes['name'] ?? null),
                'identifier' => $this->values->text($attributes['identifier'] ?? null),
                'description' => $attributes['description'] ?? null,
                'homepage' => $this->values->text($attributes['homepage'] ?? ''),
                'is_public' => $this->flag($attributes['is_public'] ?? true),
                'inherit_members' => $this->flag($attributes['inherit_members'] ?? false),
                'status' => is_numeric($attributes['status'] ?? null) ? (int) $attributes['status'] : 1,
            ], $parent);

            return ApiResult::created(['project' => $this->document($actor, $project, [], true)]);
        });
    }

    public function update(User $actor, string $key, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key, $request): ApiResult {
            $project = $this->visible($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }
            if (! $this->permissions->allowed($actor, 'edit_project', $project)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $attributes = ApiQuery::resource($request, 'project');
            if (array_key_exists('name', $attributes)) {
                $name = trim($this->values->text($attributes['name']));
                if ($name === '') {
                    throw new DomainException('Project name is required.');
                }
                $project->name = $name;
            }
            if (array_key_exists('description', $attributes)) {
                $project->description = $attributes['description'] === null ? null : $this->values->text($attributes['description']);
            }
            if (array_key_exists('homepage', $attributes)) {
                $project->homepage = $this->values->text($attributes['homepage']);
            }
            if (array_key_exists('is_public', $attributes)) {
                $project->is_public = $this->flag($attributes['is_public']);
            }
            if (array_key_exists('inherit_members', $attributes)) {
                $this->projects->setInheritMembers($project, $this->flag($attributes['inherit_members']));
                $project = $project->refresh();
            }
            $project->save();
            if (array_key_exists('parent_id', $attributes)) {
                $parent = null;
                if ($attributes['parent_id'] !== null && $attributes['parent_id'] !== '') {
                    $found = $this->visible($actor, (string) $attributes['parent_id']);
                    if ($found instanceof ApiResult) {
                        return $found;
                    }
                    $parent = $found;
                }
                $this->projects->move($project, $parent);
            }

            return ApiResult::noContent();
        });
    }

    public function destroy(User $actor, string $key): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key): ApiResult {
            $project = $this->visible($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }
            if (! $this->permissions->allowed($actor, 'delete_project', $project)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $this->deleteProject($project);

            return ApiResult::noContent();
        });
    }

    public function memberships(User $actor, string $key, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key, $request): ApiResult {
            $project = $this->membershipProject($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $page = ApiPage::from($request);
            $rows = $this->membershipRows($project);
            $slice = $page->slice($rows);

            return ApiResult::ok($this->calls->collection('memberships', $slice['rows'], $page, $slice['total']));
        });
    }

    public function storeMembership(User $actor, string $key, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key, $request): ApiResult {
            $project = $this->membershipProject($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $attributes = ApiQuery::resource($request, 'membership');
            $principal = $this->principal($attributes);
            if (! $principal instanceof User) {
                return ApiResult::fail(422, 'User is required.');
            }
            $roleIds = $this->roleIds($attributes);
            if ($roleIds === []) {
                return ApiResult::fail(422, 'Role is required.');
            }
            foreach ($roleIds as $roleId) {
                $role = Role::query()->find($roleId);
                if (! $role instanceof Role) {
                    return ApiResult::fail(422, 'Role does not exist.');
                }
                $this->managedRoles->assign($actor, $project, $principal, $role);
            }
            $member = Member::query()
                ->where('project_id', $project->id)
                ->where('user_id', $principal->id)
                ->first();
            if (! $member instanceof Member) {
                return ApiResult::fail(422, 'Membership was not created.');
            }

            return ApiResult::created(['membership' => $this->membershipDocument($member)]);
        });
    }

    public function showMembership(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $member = $this->readableMember($actor, $id);
            if ($member instanceof ApiResult) {
                return $member;
            }

            return ApiResult::ok(['membership' => $this->membershipDocument($member)]);
        });
    }

    public function updateMembership(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $member = $this->readableMember($actor, $id);
            if ($member instanceof ApiResult) {
                return $member;
            }
            $project = $member->project;
            $principal = $member->user;
            if (! $project instanceof Project || ! $principal instanceof User) {
                return ApiResult::fail(404, 'Not found');
            }
            $attributes = ApiQuery::resource($request, 'membership');
            $roleIds = $this->roleIds($attributes);
            if ($roleIds === []) {
                return ApiResult::fail(422, 'Role is required.');
            }
            foreach ($member->memberRoles as $memberRole) {
                if ($memberRole->inherited_from === null) {
                    $this->memberships->revokeRole($memberRole);
                }
            }
            $member = Member::query()->find($id);
            foreach ($roleIds as $roleId) {
                $role = Role::query()->find($roleId);
                if (! $role instanceof Role) {
                    return ApiResult::fail(422, 'Role does not exist.');
                }
                $this->managedRoles->assign($actor, $project, $principal, $role);
            }
            $fresh = Member::query()
                ->where('project_id', $project->id)
                ->where('user_id', $principal->id)
                ->first();
            if (! $fresh instanceof Member) {
                return ApiResult::fail(422, 'Membership was not updated.');
            }
            if ($member instanceof Member && (int) $member->id !== (int) $fresh->id) {
                return ApiResult::ok(['membership' => $this->membershipDocument($fresh)]);
            }

            return ApiResult::noContent();
        });
    }

    public function destroyMembership(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $member = $this->readableMember($actor, $id);
            if ($member instanceof ApiResult) {
                return $member;
            }
            foreach ($member->memberRoles()->get() as $memberRole) {
                if ($memberRole->inherited_from === null) {
                    $this->memberships->revokeRole($memberRole);
                }
            }

            return ApiResult::noContent();
        });
    }

    public function versions(User $actor, string $key): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key): ApiResult {
            $project = $this->visible($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $rows = [];
            foreach (Version::query()->where('project_id', $project->id)->orderBy('name')->orderBy('id')->get() as $version) {
                $rows[] = $this->versionDocument($version);
            }

            return ApiResult::ok(['versions' => $rows]);
        });
    }

    public function showVersion(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $version = $this->readableVersion($actor, $id);
            if ($version instanceof ApiResult) {
                return $version;
            }

            return ApiResult::ok(['version' => $this->versionDocument($version)]);
        });
    }

    public function storeVersion(User $actor, string $key, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key, $request): ApiResult {
            $project = $this->managedProject($actor, $key, 'manage_versions');
            if ($project instanceof ApiResult) {
                return $project;
            }
            $attributes = ApiQuery::resource($request, 'version');
            $name = trim($this->values->text($attributes['name'] ?? null));
            if ($name === '') {
                throw new DomainException('Version name is required.');
            }
            $version = Version::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'description' => $this->values->text($attributes['description'] ?? ''),
                'status' => $this->versionStatus($attributes['status'] ?? 'open'),
                'sharing' => $this->sharing($attributes['sharing'] ?? 'none'),
                'effective_date' => $this->optionalDay($attributes['due_date'] ?? null),
                'wiki_page_title' => $this->optionalText($attributes['wiki_page_title'] ?? null),
            ]);

            return ApiResult::created(['version' => $this->versionDocument($version)]);
        });
    }

    public function updateVersion(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $version = $this->readableVersion($actor, $id);
            if ($version instanceof ApiResult) {
                return $version;
            }
            $project = $version->project;
            if (! $project instanceof Project || ! $this->permissions->allowed($actor, 'manage_versions', $project)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $attributes = ApiQuery::resource($request, 'version');
            if (array_key_exists('name', $attributes)) {
                $name = trim($this->values->text($attributes['name']));
                if ($name === '') {
                    throw new DomainException('Version name is required.');
                }
                $version->name = $name;
            }
            if (array_key_exists('description', $attributes)) {
                $version->description = $this->values->text($attributes['description']);
            }
            if (array_key_exists('status', $attributes)) {
                $version->status = $this->versionStatus($attributes['status']);
            }
            if (array_key_exists('sharing', $attributes)) {
                $version->sharing = $this->sharing($attributes['sharing']);
            }
            if (array_key_exists('due_date', $attributes)) {
                $version->effective_date = $this->optionalDay($attributes['due_date']);
            }
            if (array_key_exists('wiki_page_title', $attributes)) {
                $version->wiki_page_title = $this->optionalText($attributes['wiki_page_title']);
            }
            $version->save();

            return ApiResult::noContent();
        });
    }

    public function destroyVersion(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $version = $this->readableVersion($actor, $id);
            if ($version instanceof ApiResult) {
                return $version;
            }
            $project = $version->project;
            if (! $project instanceof Project || ! $this->permissions->allowed($actor, 'manage_versions', $project)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            if (Issue::query()->where('fixed_version_id', $version->id)->exists()) {
                throw new DomainException('Version is used by an issue.');
            }
            $version->delete();

            return ApiResult::noContent();
        });
    }

    public function categories(User $actor, string $key): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key): ApiResult {
            $project = $this->visible($actor, $key);
            if ($project instanceof ApiResult) {
                return $project;
            }
            $rows = [];
            foreach (IssueCategory::query()->where('project_id', $project->id)->orderBy('name')->orderBy('id')->get() as $category) {
                $rows[] = $this->categoryDocument($category);
            }

            return ApiResult::ok(['issue_categories' => $rows]);
        });
    }

    public function showCategory(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $category = $this->readableCategory($actor, $id);
            if ($category instanceof ApiResult) {
                return $category;
            }

            return ApiResult::ok(['issue_category' => $this->categoryDocument($category)]);
        });
    }

    public function storeCategory(User $actor, string $key, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key, $request): ApiResult {
            $project = $this->managedProject($actor, $key, 'manage_categories');
            if ($project instanceof ApiResult) {
                return $project;
            }
            $attributes = ApiQuery::resource($request, 'issue_category');
            $name = trim($this->values->text($attributes['name'] ?? null));
            if ($name === '') {
                throw new DomainException('Category name is required.');
            }
            $category = IssueCategory::query()->create([
                'project_id' => $project->id,
                'name' => $name,
                'assigned_to_id' => $this->optionalId($attributes['assigned_to_id'] ?? null),
            ]);

            return ApiResult::created(['issue_category' => $this->categoryDocument($category)]);
        });
    }

    public function updateCategory(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $category = $this->readableCategory($actor, $id);
            if ($category instanceof ApiResult) {
                return $category;
            }
            $project = $category->project;
            if (! $project instanceof Project || ! $this->permissions->allowed($actor, 'manage_categories', $project)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $attributes = ApiQuery::resource($request, 'issue_category');
            if (array_key_exists('name', $attributes)) {
                $name = trim($this->values->text($attributes['name']));
                if ($name === '') {
                    throw new DomainException('Category name is required.');
                }
                $category->name = $name;
            }
            if (array_key_exists('assigned_to_id', $attributes)) {
                $category->assigned_to_id = $this->optionalId($attributes['assigned_to_id']);
            }
            $category->save();

            return ApiResult::noContent();
        });
    }

    public function destroyCategory(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $category = $this->readableCategory($actor, $id);
            if ($category instanceof ApiResult) {
                return $category;
            }
            $project = $category->project;
            if (! $project instanceof Project || ! $this->permissions->allowed($actor, 'manage_categories', $project)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            if (Issue::query()->where('category_id', $category->id)->exists()) {
                throw new DomainException('Category is used by an issue.');
            }
            $category->delete();

            return ApiResult::noContent();
        });
    }

    /**
     * @param  array<string, true>  $includes
     * @return array<string, mixed>
     */
    private function document(User $actor, Project $project, array $includes, bool $detail): array
    {
        $row = [
            'id' => (int) $project->id,
            'name' => (string) $project->name,
            'identifier' => (string) $project->identifier,
            'description' => $this->values->text($project->description),
            'homepage' => $this->values->text($project->homepage),
            'status' => (int) $project->status,
            'is_public' => (bool) $project->is_public,
            'inherit_members' => (bool) $project->inherit_members,
        ];
        if ($project->parent_id !== null) {
            $parent = Project::query()->find($project->parent_id);
            if ($parent instanceof Project) {
                $row['parent'] = $this->values->ref((int) $parent->id, (string) $parent->name);
            }
        }
        $row['created_on'] = $this->values->stamp($project->created_on);
        $row['updated_on'] = $this->values->stamp($project->updated_on);
        if ($detail) {
            $row['custom_fields'] = $this->values->customFields($actor, $project);
        }
        if (isset($includes['trackers'])) {
            $row['trackers'] = [];
            foreach ($project->trackers()->orderBy('position')->orderBy('id')->get() as $tracker) {
                $row['trackers'][] = $this->values->ref((int) $tracker->id, (string) $tracker->name);
            }
        }
        if (isset($includes['issue_categories'])) {
            $row['issue_categories'] = [];
            foreach (IssueCategory::query()->where('project_id', $project->id)->orderBy('name')->orderBy('id')->get() as $category) {
                $row['issue_categories'][] = $this->values->ref((int) $category->id, (string) $category->name);
            }
        }
        if (isset($includes['enabled_modules'])) {
            $row['enabled_modules'] = [];
            foreach (EnabledModule::query()->where('project_id', $project->id)->orderBy('id')->get() as $module) {
                $row['enabled_modules'][] = [
                    'id' => (int) $module->id,
                    'name' => (string) $module->name,
                ];
            }
        }
        if (isset($includes['time_entry_activities'])) {
            $row['time_entry_activities'] = $this->activities($project);
        }

        return $row;
    }

    /**
     * @return list<array{id: int, name: string, is_default: bool}>
     */
    private function activities(Project $project): array
    {
        $rows = [];
        $activities = Enumeration::query()
            ->where('type', 'TimeEntryActivity')
            ->where('active', true)
            ->where(function (Builder $query) use ($project): void {
                $query->whereNull('project_id')->orWhere('project_id', $project->id);
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        foreach ($activities as $activity) {
            $rows[] = [
                'id' => (int) $activity->id,
                'name' => (string) $activity->name,
                'is_default' => (bool) $activity->is_default,
            ];
        }

        return $rows;
    }

    /**
     * @return list<Project>
     */
    private function visibleProjects(User $actor): array
    {
        $rows = [];
        foreach (Project::query()->orderBy('name')->orderBy('id')->get() as $project) {
            if ($this->permissions->projectVisible($actor, $project)) {
                $rows[] = $project;
            }
        }

        return $rows;
    }

    private function visible(User $actor, string $key): Project|ApiResult
    {
        $project = $this->locate($key);
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }

        return $project;
    }

    private function locate(string $key): ?Project
    {
        if (preg_match('/^\d+$/', $key) === 1) {
            $found = Project::query()->find((int) $key);

            return $found instanceof Project ? $found : null;
        }
        $found = Project::query()->where('identifier', $key)->first();

        return $found instanceof Project ? $found : null;
    }

    private function membershipProject(User $actor, string $key): Project|ApiResult
    {
        $project = $this->visible($actor, $key);
        if ($project instanceof ApiResult) {
            return $project;
        }
        if (! $this->permissions->allowed($actor, 'view_members', $project)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $project;
    }

    private function managedProject(User $actor, string $key, string $permission): Project|ApiResult
    {
        $project = $this->visible($actor, $key);
        if ($project instanceof ApiResult) {
            return $project;
        }
        if (! $this->permissions->allowed($actor, $permission, $project)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $project;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function membershipRows(Project $project): array
    {
        $rows = [];
        $members = Member::query()->where('project_id', $project->id)->orderBy('id')->get();
        foreach ($members as $member) {
            $rows[] = $this->membershipDocument($member);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipDocument(Member $member): array
    {
        $member->loadMissing(['project', 'user', 'memberRoles.role']);
        $project = $member->project;
        $principal = $member->user;
        $row = [
            'id' => (int) $member->id,
            'project' => $project instanceof Project ? $this->values->ref((int) $project->id, (string) $project->name) : null,
        ];
        if ($principal instanceof User) {
            $key = $principal->type === User::TYPE_GROUP ? 'group' : 'user';
            $row[$key] = $this->values->ref((int) $principal->id, $this->values->personName($principal));
        }
        $roles = [];
        foreach ($member->memberRoles as $memberRole) {
            $role = $memberRole->role;
            if ($role instanceof Role) {
                $roles[] = $this->values->ref((int) $role->id, (string) $role->name);
            }
        }
        $row['roles'] = $roles;

        return $row;
    }

    private function readableMember(User $actor, int $id): Member|ApiResult
    {
        $member = Member::query()->find($id);
        if (! $member instanceof Member) {
            return ApiResult::fail(404, 'Not found');
        }
        $project = $member->project;
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }
        if (! $this->permissions->allowed($actor, 'view_members', $project)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function principal(array $attributes): ?User
    {
        $raw = $attributes['user_id'] ?? $attributes['group_id'] ?? null;
        if (! is_numeric($raw)) {
            return null;
        }
        $user = User::query()->find((int) $raw);

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<int>
     */
    private function roleIds(array $attributes): array
    {
        $raw = $attributes['role_ids'] ?? null;
        if (is_numeric($raw)) {
            return [(int) $raw];
        }
        if (! is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionDocument(Version $version): array
    {
        $version->loadMissing('project');
        $project = $version->project;

        return [
            'id' => (int) $version->id,
            'project' => $project instanceof Project ? $this->values->ref((int) $project->id, (string) $project->name) : null,
            'name' => (string) $version->name,
            'description' => $this->values->text($version->description),
            'status' => (string) $version->status,
            'due_date' => $this->values->day($version->effective_date),
            'sharing' => (string) $version->sharing,
            'wiki_page_title' => $version->wiki_page_title === null || $version->wiki_page_title === '' ? null : (string) $version->wiki_page_title,
            'created_on' => $this->values->stamp($version->created_on),
            'updated_on' => $this->values->stamp($version->updated_on),
        ];
    }

    private function readableVersion(User $actor, int $id): Version|ApiResult
    {
        $version = Version::query()->find($id);
        if (! $version instanceof Version) {
            return ApiResult::fail(404, 'Not found');
        }
        $project = $version->project;
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryDocument(IssueCategory $category): array
    {
        $category->loadMissing(['project', 'assignedTo']);
        $project = $category->project;
        $row = [
            'id' => (int) $category->id,
            'project' => $project instanceof Project ? $this->values->ref((int) $project->id, (string) $project->name) : null,
            'name' => (string) $category->name,
        ];
        $assignee = $category->assignedTo;
        if ($assignee instanceof User) {
            $row['assigned_to'] = $this->values->ref((int) $assignee->id, $this->values->personName($assignee));
        }

        return $row;
    }

    private function readableCategory(User $actor, int $id): IssueCategory|ApiResult
    {
        $category = IssueCategory::query()->find($id);
        if (! $category instanceof IssueCategory) {
            return ApiResult::fail(404, 'Not found');
        }
        $project = $category->project;
        if (! $project instanceof Project || ! $this->permissions->projectVisible($actor, $project)) {
            return ApiResult::fail(404, 'Not found');
        }

        return $category;
    }

    private function deleteProject(Project $project): void
    {
        if ((int) $project->rgt - (int) $project->lft !== 1) {
            throw new DomainException('Project has subprojects.');
        }
        if (Issue::query()->where('project_id', $project->id)->exists()) {
            throw new DomainException('Project has issues.');
        }

        DB::transaction(function () use ($project): void {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->first();
            if (! $locked instanceof Project) {
                throw new DomainException('Project does not exist.');
            }
            if ((int) $locked->rgt - (int) $locked->lft !== 1) {
                throw new DomainException('Project has subprojects.');
            }
            $memberIds = Member::query()->where('project_id', $locked->id)->pluck('id');
            if ($memberIds->isNotEmpty()) {
                MemberRole::query()->whereIn('member_id', $memberIds)->delete();
                Member::query()->where('project_id', $locked->id)->delete();
            }
            EnabledModule::query()->where('project_id', $locked->id)->delete();
            Version::query()->where('project_id', $locked->id)->delete();
            IssueCategory::query()->where('project_id', $locked->id)->delete();
            (new NestedSet('projects'))->closeGap((int) $locked->rgt, 2);
            $locked->delete();
        });
    }

    private function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return ! in_array(strtolower($value), ['0', 'false', ''], true);
        }

        return false;
    }

    private function versionStatus(mixed $value): string
    {
        $status = is_string($value) ? $value : 'open';
        if (! in_array($status, ['open', 'locked', 'closed'], true)) {
            throw new DomainException('Version status is not supported.');
        }

        return $status;
    }

    private function sharing(mixed $value): string
    {
        $sharing = is_string($value) ? $value : 'none';
        if (! in_array($sharing, ['none', 'descendants', 'hierarchy', 'tree', 'system'], true)) {
            throw new DomainException('Version sharing is not supported.');
        }

        return $sharing;
    }

    private function optionalDay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $day = $this->values->day($value);
        if ($day === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new DomainException('Due date is not a date.');
        }

        return $day;
    }

    private function optionalText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->values->text($value);
    }

    private function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new DomainException('Assignee does not exist.');
        }

        return (int) $value;
    }
}
