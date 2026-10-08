<?php

namespace App\Providers;

use App\Auth\RedmineUserProvider;
use App\Domain\Acl\PermissionCatalog;
use App\Domain\Acl\PermissionService;
use App\Domain\Attachments\AbsentThumbnailDecoder;
use App\Domain\Attachments\InterventionThumbnailDecoder;
use App\Domain\Attachments\ThumbnailDecoder;
use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\Ldap\LdapDirectory;
use App\Domain\Auth\Ldap\MemoryLdapDirectory;
use App\Models\Document;
use App\Models\Issue;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Policies\IssuePolicy;
use App\Policies\NewsPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionCatalog::class);
        $this->app->singleton(LdapDirectory::class, MemoryLdapDirectory::class);
        $this->app->bind(ThumbnailDecoder::class, function (Application $app): ThumbnailDecoder {
            if (InterventionThumbnailDecoder::present()) {
                return $app->make(InterventionThumbnailDecoder::class);
            }

            return new AbsentThumbnailDecoder;
        });

        Auth::provider('redmine', $this->redmineUserProvider(...));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function redmineUserProvider(Application $app, array $config): RedmineUserProvider
    {
        $model = $config['model'] ?? User::class;
        if (! is_string($model)) {
            throw new RuntimeException('Auth user model must be a class name.');
        }

        return new RedmineUserProvider(
            $app->make(Hasher::class),
            $model,
            $app->make(CredentialChecker::class),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Issue::class, IssuePolicy::class);
        Gate::policy(News::class, NewsPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

        foreach ($this->app->make(PermissionCatalog::class)->definitions() as $definition) {
            $name = $definition->name;
            Gate::define($name, function (mixed $user, mixed $project = null) use ($name): bool {
                $actor = $user instanceof User ? $user : null;
                $target = $project instanceof Project ? $project : null;

                return $this->app->make(PermissionService::class)->allowed($actor, $name, $target);
            });
        }

        Event::listen(Login::class, function (Login $event): void {
            $user = $event->user;
            if (! $user instanceof User) {
                return;
            }

            $user->forceFill([
                'last_login_on' => now()->startOfSecond(),
            ])->save();
        });
    }
}
