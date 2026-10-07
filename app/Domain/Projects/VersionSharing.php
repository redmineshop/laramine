<?php

namespace App\Domain\Projects;

/**
 * Values stored in `versions.sharing`.
 *
 * `none` stays on the version's project. `descendants` reaches subprojects.
 * `hierarchy` reaches ancestors and descendants. `tree` reaches the project
 * tree under the same root. `system` reaches every project.
 */
enum VersionSharing: string
{
    case None = 'none';
    case Descendants = 'descendants';
    case Hierarchy = 'hierarchy';
    case Tree = 'tree';
    case System = 'system';
}
