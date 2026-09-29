<?php

namespace App\Providers;

use App\Domain\Acl\PermissionCatalog;
use App\Domain\Acl\PermissionService;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Policies\IssuePolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionCatalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Issue::class, IssuePolicy::class);

        foreach ($this->app->make(PermissionCatalog::class)->definitions() as $definition) {
            $name = $definition->name;
            Gate::define($name, function (mixed $user, mixed $project = null) use ($name): bool {
                $actor = $user instanceof User ? $user : null;
                $target = $project instanceof Project ? $project : null;

                return $this->app->make(PermissionService::class)->allowed($actor, $name, $target);
            });
        }
    }
}
