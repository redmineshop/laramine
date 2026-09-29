<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 project tree, versions, categories, and enumerations.
     *
     * Projects keep nested-set columns `parent_id`, `lft`, and `rgt` (no `root_id`).
     */
    public function up(): void
    {
        Schema::create('enumerations', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('name', 30)->default('');
            $table->integer('parent_id')->nullable();
            $table->integer('position')->nullable();
            $table->string('position_name', 30)->nullable();
            $table->integer('project_id')->nullable();
            $table->string('type')->nullable();

            $table->index(['id', 'type'], 'index_enumerations_on_id_and_type');
            $table->index('project_id', 'index_enumerations_on_project_id');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on')->nullable();
            $table->integer('default_assigned_to_id')->nullable();
            $table->integer('default_issue_query_id')->nullable();
            $table->integer('default_version_id')->nullable();
            $table->text('description')->nullable();
            $table->string('homepage')->default('')->nullable();
            $table->string('identifier')->nullable();
            $table->boolean('inherit_members')->default(false);
            $table->boolean('is_public')->default(true);
            $table->integer('lft')->nullable();
            $table->string('name')->default('');
            $table->integer('parent_id')->nullable();
            $table->integer('rgt')->nullable();
            $table->integer('status')->default(1);
            $table->dateTime('updated_on')->nullable();

            $table->unique('identifier', 'index_projects_on_identifier');
            $table->index('lft', 'index_projects_on_lft');
            $table->index('rgt', 'index_projects_on_rgt');
        });

        Schema::create('versions', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on')->nullable();
            $table->string('description')->default('')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('name')->nullable();
            $table->integer('project_id')->default(0);
            $table->string('sharing')->default('none');
            $table->string('status')->default('open')->nullable();
            $table->dateTime('updated_on')->nullable();
            $table->string('wiki_page_title')->nullable();

            $table->index('project_id', 'versions_project_id');
            $table->index('sharing', 'index_versions_on_sharing');
        });

        Schema::create('issue_categories', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('assigned_to_id')->nullable();
            $table->string('name', 60)->default('');
            $table->integer('project_id')->default(0);

            $table->index('assigned_to_id', 'index_issue_categories_on_assigned_to_id');
            $table->index('project_id', 'issue_categories_project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issue_categories');
        Schema::dropIfExists('versions');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('enumerations');
    }
};
