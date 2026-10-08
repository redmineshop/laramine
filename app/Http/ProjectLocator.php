<?php

namespace App\Http;

use App\Models\Project;

/**
 * Resolves a project segment that is an identifier or a numeric id.
 *
 * The identifier wins when both could match.
 */
final class ProjectLocator
{
    public function find(string $key): Project
    {
        $project = Project::query()->where('identifier', $key)->first();
        if (! $project instanceof Project && preg_match('/^[1-9]\d*$/', $key) === 1) {
            $project = Project::query()->find((int) $key);
        }
        if (! $project instanceof Project) {
            abort(404);
        }

        return $project;
    }
}
