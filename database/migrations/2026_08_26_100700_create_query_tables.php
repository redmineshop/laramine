<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 saved queries and CSV import jobs.
     *
     * `filters` stays opaque text. Operator semantics are not implemented here.
     */
    public function up(): void
    {
        Schema::create('queries', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->text('column_names')->nullable();
            $table->string('description')->nullable();
            $table->text('filters')->nullable();
            $table->string('group_by')->nullable();
            $table->string('name')->default('');
            $table->text('options')->nullable();
            $table->integer('project_id')->nullable();
            $table->text('sort_criteria')->nullable();
            $table->string('type')->nullable();
            $table->integer('user_id')->default(0);
            $table->integer('visibility')->default(0)->nullable();

            $table->index('project_id', 'index_queries_on_project_id');
            $table->index('user_id', 'index_queries_on_user_id');
        });

        Schema::create('queries_roles', function (Blueprint $table) {
            $table->integer('query_id');
            $table->integer('role_id');

            $table->unique(['query_id', 'role_id'], 'queries_roles_ids');
        });

        Schema::create('imports', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_at');
            $table->string('filename')->nullable();
            $table->boolean('finished')->default(false);
            $table->text('settings')->nullable();
            $table->integer('total_items')->nullable();
            $table->string('type')->nullable();
            $table->dateTime('updated_at');
            $table->integer('user_id');
        });

        Schema::create('import_items', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('import_id');
            $table->text('message')->nullable();
            $table->integer('obj_id')->nullable();
            $table->integer('position');
            $table->string('unique_id')->nullable();

            $table->index(['import_id', 'unique_id'], 'index_import_items_on_import_id_and_unique_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_items');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('queries_roles');
        Schema::dropIfExists('queries');
    }
};
