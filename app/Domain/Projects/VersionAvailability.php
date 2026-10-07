<?php

namespace App\Domain\Projects;

use App\Models\Project;
use App\Models\Version;

/**
 * Decides whether a version may be selected on a project.
 *
 * The version's own project can always select it. Other projects follow
 * `versions.sharing`. An unknown sharing value is not shared.
 */
final class VersionAvailability
{
    public function available(Version $version, Project $target): bool
    {
        $owner = $this->project((int) $version->project_id);
        $freshTarget = $this->project((int) $target->id);
        if ($freshTarget instanceof Project) {
            $target = $freshTarget;
        }
        if (! $owner instanceof Project) {
            return false;
        }
        if ((int) $owner->id === (int) $target->id) {
            return true;
        }

        $sharing = VersionSharing::tryFrom((string) $version->sharing);
        if ($sharing === null) {
            return false;
        }

        return match ($sharing) {
            VersionSharing::None => false,
            VersionSharing::System => true,
            VersionSharing::Tree => $this->rootId($owner) === $this->rootId($target),
            VersionSharing::Hierarchy => $this->isAncestor($owner, $target) || $this->isAncestor($target, $owner),
            VersionSharing::Descendants => $this->isAncestor($owner, $target),
        };
    }

    private function project(int $id): ?Project
    {
        if ($id <= 0) {
            return null;
        }

        $project = Project::query()->find($id);

        return $project instanceof Project ? $project : null;
    }

    private function rootId(Project $project): int
    {
        $current = $project;
        $seen = [];
        for ($guard = 0; $guard < 64; $guard++) {
            $id = (int) $current->id;
            if (isset($seen[$id])) {
                return $id;
            }
            $seen[$id] = true;
            $parentId = $current->parent_id;
            if ($parentId === null) {
                return $id;
            }
            $parent = $this->project((int) $parentId);
            if (! $parent instanceof Project) {
                return $id;
            }
            $current = $parent;
        }

        return (int) $current->id;
    }

    private function isAncestor(Project $ancestor, Project $descendant): bool
    {
        return (int) $ancestor->lft < (int) $descendant->lft
            && (int) $ancestor->rgt > (int) $descendant->rgt;
    }
}
