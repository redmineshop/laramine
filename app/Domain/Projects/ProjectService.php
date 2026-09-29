<?php

namespace App\Domain\Projects;

use App\Domain\Acl\MembershipService;
use App\Domain\Acl\PermissionCatalog;
use App\Domain\DomainException;
use App\Models\EnabledModule;
use App\Models\Project;
use App\Models\Tracker;

/**
 * Project writes: nested set, enabled modules, and tracker links.
 */
final class ProjectService
{
    public function __construct(
        private readonly ProjectTree $trees,
        private readonly MembershipService $memberships,
        private readonly PermissionCatalog $catalog,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     identifier: string,
     *     description?: string|null,
     *     homepage?: string|null,
     *     is_public?: bool,
     *     inherit_members?: bool,
     *     status?: int
     * }  $attributes
     */
    public function create(array $attributes, ?Project $parent = null): Project
    {
        $name = trim($attributes['name']);
        $identifier = $attributes['identifier'];
        if ($name === '') {
            throw new DomainException('Project name is required.');
        }
        $this->assertIdentifier($identifier);

        $inherit = (bool) ($attributes['inherit_members'] ?? false);
        $project = $this->trees->create([
            'name' => $name,
            'identifier' => $identifier,
            'description' => $attributes['description'] ?? null,
            'homepage' => $attributes['homepage'] ?? '',
            'is_public' => $attributes['is_public'] ?? true,
            'inherit_members' => $inherit,
            'status' => $attributes['status'] ?? 1,
        ], $parent);

        if ($inherit) {
            $this->memberships->copyInheritedFromParent($project);
        }

        return $project->refresh();
    }

    public function move(Project $project, ?Project $newParent): Project
    {
        return $this->trees->move($project, $newParent);
    }

    public function setInheritMembers(Project $project, bool $inherit): Project
    {
        $project->inherit_members = $inherit;
        $project->save();

        if ($inherit) {
            $this->memberships->copyInheritedFromParent($project->refresh());
        } else {
            $this->memberships->forgetCrossProjectInheritance($project);
        }

        return $project->refresh();
    }

    public function enableModule(Project $project, string $module): EnabledModule
    {
        if (! $this->catalog->isEnabledModule($module)) {
            throw new DomainException('Unknown module: '.$module);
        }

        $existing = $project->enabledModules()->where('name', $module)->first();
        if ($existing !== null) {
            return $existing;
        }

        return $project->enabledModules()->create([
            'name' => $module,
        ]);
    }

    public function disableModule(Project $project, string $module): void
    {
        $project->enabledModules()->where('name', $module)->delete();
    }

    public function moduleEnabled(Project $project, string $module): bool
    {
        return $project->isModuleEnabled($module);
    }

    public function attachTracker(Project $project, Tracker $tracker): void
    {
        $project->trackers()->syncWithoutDetaching([$tracker->id]);
    }

    public function detachTracker(Project $project, Tracker $tracker): void
    {
        $project->trackers()->detach($tracker->id);
    }

    public function hasTracker(Project $project, Tracker $tracker): bool
    {
        return $project->trackers()->where('trackers.id', $tracker->id)->exists();
    }

    public function assertConsistent(): void
    {
        $this->trees->assertConsistent();
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,99}$/', $identifier)) {
            throw new DomainException('Project identifier must start with a letter and contain only lowercase letters, digits, and hyphens.');
        }

        if (Project::query()->where('identifier', $identifier)->exists()) {
            throw new DomainException('Project identifier is already in use.');
        }
    }
}
