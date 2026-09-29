<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 custom field definitions and polymorphic values.
     *
     * `possible_values`, `format_store`, and `value` stay text. Format rules are not applied here.
     */
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->text('default_value')->nullable();
            $table->text('description')->nullable();
            $table->boolean('editable')->default(true)->nullable();
            $table->string('field_format', 30)->default('');
            $table->text('format_store')->nullable();
            $table->boolean('is_filter')->default(false);
            $table->boolean('is_for_all')->default(false);
            $table->boolean('is_required')->default(false);
            $table->integer('max_length')->nullable();
            $table->integer('min_length')->nullable();
            $table->boolean('multiple')->default(false)->nullable();
            $table->string('name', 30)->default('');
            $table->integer('position')->nullable();
            $table->text('possible_values')->nullable();
            $table->string('regexp')->default('')->nullable();
            $table->boolean('searchable')->default(false)->nullable();
            $table->string('type', 30)->default('');
            $table->boolean('visible')->default(true);

            $table->index(['id', 'type'], 'index_custom_fields_on_id_and_type');
        });

        Schema::create('custom_fields_projects', function (Blueprint $table) {
            $table->integer('custom_field_id')->default(0);
            $table->integer('project_id')->default(0);

            $table->unique(
                ['custom_field_id', 'project_id'],
                'index_custom_fields_projects_on_custom_field_id_and_project_id',
            );
        });

        Schema::create('custom_fields_roles', function (Blueprint $table) {
            $table->integer('custom_field_id');
            $table->integer('role_id');

            $table->unique(['custom_field_id', 'role_id'], 'custom_fields_roles_ids');
        });

        Schema::create('custom_fields_trackers', function (Blueprint $table) {
            $table->integer('custom_field_id')->default(0);
            $table->integer('tracker_id')->default(0);

            $table->unique(
                ['custom_field_id', 'tracker_id'],
                'index_custom_fields_trackers_on_custom_field_id_and_tracker_id',
            );
        });

        Schema::create('custom_values', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('custom_field_id')->default(0);
            $table->integer('customized_id')->default(0);
            $table->string('customized_type', 30)->default('');
            $table->text('value')->nullable();

            $table->index('custom_field_id', 'index_custom_values_on_custom_field_id');
            $table->index(
                ['customized_type', 'customized_id', 'custom_field_id'],
                'custom_values_customized_custom_field',
            );
        });

        Schema::create('custom_field_enumerations', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('active')->default(true);
            $table->integer('custom_field_id');
            $table->string('name');
            $table->integer('position')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('custom_field_enumerations');
        Schema::dropIfExists('custom_values');
        Schema::dropIfExists('custom_fields_trackers');
        Schema::dropIfExists('custom_fields_roles');
        Schema::dropIfExists('custom_fields_projects');
        Schema::dropIfExists('custom_fields');
    }
};
