<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 issue core.
     *
     * Issues keep nested-set columns `parent_id`, `root_id`, `lft`, and `rgt`.
     */
    public function up(): void
    {
        Schema::create('issue_statuses', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('default_done_ratio')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->string('name', 30)->default('');
            $table->integer('position')->nullable();

            $table->index('is_closed', 'index_issue_statuses_on_is_closed');
            $table->index('position', 'index_issue_statuses_on_position');
        });

        Schema::create('trackers', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('default_status_id')->nullable();
            $table->string('description')->nullable();
            $table->integer('fields_bits')->default(0)->nullable();
            $table->boolean('is_in_roadmap')->default(true);
            $table->string('name', 30)->default('');
            $table->integer('position')->nullable();
            $table->boolean('private_by_default')->default(false);
        });

        Schema::create('projects_trackers', function (Blueprint $table) {
            $table->integer('project_id')->default(0);
            $table->integer('tracker_id')->default(0);

            $table->unique(['project_id', 'tracker_id'], 'projects_trackers_unique');
            $table->index('project_id', 'projects_trackers_project_id');
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('assigned_to_id')->nullable();
            $table->integer('author_id');
            $table->integer('category_id')->nullable();
            $table->dateTime('closed_on')->nullable();
            $table->dateTime('created_on')->nullable();
            $table->text('description')->nullable();
            $table->integer('done_ratio')->default(0);
            $table->date('due_date')->nullable();
            $table->float('estimated_hours', 0)->nullable();
            $table->integer('fixed_version_id')->nullable();
            $table->boolean('is_private')->default(false);
            $table->integer('lft')->nullable();
            $table->integer('lock_version')->default(0);
            $table->integer('parent_id')->nullable();
            $table->integer('priority_id');
            $table->integer('project_id');
            $table->integer('rgt')->nullable();
            $table->integer('root_id')->nullable();
            $table->date('start_date')->nullable();
            $table->integer('status_id');
            $table->string('subject')->default('');
            $table->integer('tracker_id');
            $table->dateTime('updated_on')->nullable();

            $table->index('assigned_to_id', 'index_issues_on_assigned_to_id');
            $table->index('author_id', 'index_issues_on_author_id');
            $table->index('category_id', 'index_issues_on_category_id');
            $table->index('created_on', 'index_issues_on_created_on');
            $table->index('fixed_version_id', 'index_issues_on_fixed_version_id');
            $table->index('parent_id', 'index_issues_on_parent_id');
            $table->index('priority_id', 'index_issues_on_priority_id');
            $table->index('project_id', 'issues_project_id');
            $table->index(['root_id', 'lft', 'rgt'], 'index_issues_on_root_id_and_lft_and_rgt');
            $table->index('status_id', 'index_issues_on_status_id');
            $table->index('tracker_id', 'index_issues_on_tracker_id');
        });

        Schema::create('issue_relations', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('delay')->nullable();
            $table->integer('issue_from_id');
            $table->integer('issue_to_id');
            $table->string('relation_type')->default('');

            $table->unique(
                ['issue_from_id', 'issue_to_id'],
                'index_issue_relations_on_issue_from_id_and_issue_to_id',
            );
            $table->index('issue_from_id', 'index_issue_relations_on_issue_from_id');
            $table->index('issue_to_id', 'index_issue_relations_on_issue_to_id');
        });

        Schema::create('watchers', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('user_id')->nullable();
            $table->integer('watchable_id')->default(0);
            $table->string('watchable_type')->default('');

            $table->index(['user_id', 'watchable_type'], 'watchers_user_id_type');
            $table->index('user_id', 'index_watchers_on_user_id');
            $table->index(
                ['watchable_id', 'watchable_type'],
                'index_watchers_on_watchable_id_and_watchable_type',
            );
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on');
            $table->integer('journalized_id')->default(0);
            $table->string('journalized_type', 30)->default('');
            $table->text('notes')->nullable();
            $table->boolean('private_notes')->default(false);
            $table->integer('updated_by_id')->nullable();
            $table->dateTime('updated_on')->nullable();
            $table->integer('user_id')->default(0);

            $table->index('created_on', 'index_journals_on_created_on');
            $table->index(['journalized_id', 'journalized_type'], 'journals_journalized_id');
            $table->index('journalized_id', 'index_journals_on_journalized_id');
            $table->index('user_id', 'index_journals_on_user_id');
        });

        Schema::create('journal_details', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('journal_id')->default(0);
            $table->text('old_value')->nullable();
            $table->string('prop_key', 30)->default('');
            $table->string('property', 30)->default('');
            $table->text('value')->nullable();

            $table->index('journal_id', 'journal_details_journal_id');
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('assignee')->default(false);
            $table->boolean('author')->default(false);
            $table->string('field_name', 30)->nullable();
            $table->integer('new_status_id')->default(0);
            $table->integer('old_status_id')->default(0);
            $table->integer('role_id')->default(0);
            $table->string('rule', 30)->nullable();
            $table->integer('tracker_id')->default(0);
            $table->string('type', 30)->nullable();

            $table->index('new_status_id', 'index_workflows_on_new_status_id');
            $table->index('old_status_id', 'index_workflows_on_old_status_id');
            $table->index(
                ['role_id', 'tracker_id', 'old_status_id'],
                'wkfs_role_tracker_old_status',
            );
            $table->index('role_id', 'index_workflows_on_role_id');
            $table->index('tracker_id', 'index_workflows_on_tracker_id');
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_at', 6);
            $table->integer('reactable_id');
            $table->string('reactable_type');
            $table->dateTime('updated_at', 6);
            $table->integer('user_id');

            $table->index(
                ['reactable_type', 'reactable_id', 'id'],
                'index_reactions_on_reactable_type_and_reactable_id_and_id',
            );
            $table->unique(
                ['reactable_type', 'reactable_id', 'user_id'],
                'index_reactions_on_reactable_type_and_reactable_id_and_user_id',
            );
            $table->index(['reactable_type', 'reactable_id'], 'index_reactions_on_reactable');
            $table->index('user_id', 'index_reactions_on_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('journal_details');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('watchers');
        Schema::dropIfExists('issue_relations');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('projects_trackers');
        Schema::dropIfExists('trackers');
        Schema::dropIfExists('issue_statuses');
    }
};
