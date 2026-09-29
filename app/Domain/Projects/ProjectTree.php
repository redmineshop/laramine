<?php

namespace App\Domain\Projects;

use App\Domain\Tree\NestedSet;
use App\Domain\Tree\TreeIntegrity;
use App\Domain\TreeException;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Creates and moves projects while keeping parent_id aligned with lft/rgt.
 */
final class ProjectTree
{
    private NestedSet $sets;

    public function __construct()
    {
        $this->sets = new NestedSet('projects');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?Project $parent = null): Project
    {
        return DB::transaction(function () use ($attributes, $parent): Project {
            $this->sets->lock();
            if ($parent !== null) {
                $parent = $this->lockedProject($parent->id);
                $slot = $this->sets->allocateChildSlot((int) $parent->rgt);
                $attributes['parent_id'] = $parent->id;
            } else {
                $slot = $this->sets->nextRootBounds();
                $attributes['parent_id'] = null;
            }

            $attributes['lft'] = $slot['lft'];
            $attributes['rgt'] = $slot['rgt'];

            $project = Project::query()->create($attributes);

            return $project->refresh();
        });
    }

    public function move(Project $project, ?Project $newParent): Project
    {
        return DB::transaction(function () use ($project, $newParent): Project {
            $this->sets->lock();
            $this->sets->moveWithinScope($project->id, $newParent?->id);

            return $project->refresh();
        });
    }

    public function assertConsistent(): void
    {
        TreeIntegrity::assertProjects();
    }

    private function lockedProject(int $id): Project
    {
        $project = Project::query()->whereKey($id)->lockForUpdate()->first();
        if ($project === null) {
            throw new TreeException('Parent project does not exist.');
        }

        return $project;
    }
}
