<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repositories and the changesets an issue history tab can list.
     *
     * `changes` and `changeset_parents` stay out. `repositories.project_id`
     * keeps the Redmine default of 0, so it has no database foreign key.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on')->nullable();
            $table->text('extra_info')->nullable();
            $table->string('identifier')->nullable();
            $table->boolean('is_default')->nullable()->default(false);
            $table->string('log_encoding', 64)->nullable();
            $table->string('login', 60)->nullable()->default('');
            $table->string('password')->nullable()->default('');
            $table->string('path_encoding', 64)->nullable();
            $table->integer('project_id')->default(0);
            $table->string('root_url', 255)->nullable()->default('');
            $table->string('type')->nullable();
            $table->string('url')->default('');

            $table->index('project_id', 'index_repositories_on_project_id');
        });

        Schema::create('changesets', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->text('comments')->nullable();
            $table->date('commit_date')->nullable();
            $table->dateTime('committed_on');
            $table->string('committer')->nullable();
            $table->integer('repository_id');
            $table->string('revision');
            $table->string('scmid')->nullable();
            $table->integer('user_id')->nullable();

            $table->index('committed_on', 'index_changesets_on_committed_on');
            $table->unique(['repository_id', 'revision'], 'changesets_repos_rev');
            $table->index(['repository_id', 'scmid'], 'changesets_repos_scmid');
            $table->index('repository_id', 'index_changesets_on_repository_id');
            $table->index('user_id', 'index_changesets_on_user_id');
        });

        Schema::create('changesets_issues', function (Blueprint $table) {
            $table->integer('changeset_id');
            $table->integer('issue_id');

            $table->unique(['changeset_id', 'issue_id'], 'changesets_issues_ids');
            $table->index('issue_id', 'index_changesets_issues_on_issue_id');
        });

        Schema::table('changesets', function (Blueprint $table) {
            $table->foreign('repository_id')->references('id')->on('repositories');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('changesets_issues', function (Blueprint $table) {
            $table->foreign('changeset_id')->references('id')->on('changesets');
            $table->foreign('issue_id')->references('id')->on('issues');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('changesets_issues', function (Blueprint $table) {
            $table->dropForeign(['changeset_id']);
            $table->dropForeign(['issue_id']);
        });
        Schema::table('changesets', function (Blueprint $table) {
            $table->dropForeign(['repository_id']);
            $table->dropForeign(['user_id']);
        });
        Schema::dropIfExists('changesets_issues');
        Schema::dropIfExists('changesets');
        Schema::dropIfExists('repositories');
    }
};
