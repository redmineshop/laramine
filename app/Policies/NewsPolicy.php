<?php

namespace App\Policies;

use App\Domain\Acl\PermissionService;
use App\Models\News;
use App\Models\Project;
use App\Models\User;

/**
 * News abilities. The project must still grant the matching permission.
 */
class NewsPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function view(?User $user, News $news): bool
    {
        return $this->allows($user, $news, 'view_news');
    }

    public function update(?User $user, News $news): bool
    {
        return $this->allows($user, $news, 'manage_news');
    }

    public function delete(?User $user, News $news): bool
    {
        return $this->update($user, $news);
    }

    public function comment(?User $user, News $news): bool
    {
        return $this->allows($user, $news, 'comment_news');
    }

    private function allows(?User $user, News $news, string $permission): bool
    {
        $project = $news->project;

        return $project instanceof Project && $this->permissions->allowed($user, $permission, $project);
    }
}
