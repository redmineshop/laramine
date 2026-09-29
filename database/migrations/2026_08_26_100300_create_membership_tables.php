<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 membership, group, and enabled-module tables.
     *
     * `groups_users` and `roles_managed_roles` have no surrogate primary key.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on')->nullable();
            $table->boolean('mail_notification')->default(false);
            $table->integer('project_id')->default(0);
            $table->integer('user_id')->default(0);

            $table->index('project_id', 'index_members_on_project_id');
            $table->unique(['user_id', 'project_id'], 'index_members_on_user_id_and_project_id');
            $table->index('user_id', 'index_members_on_user_id');
        });

        Schema::create('member_roles', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('inherited_from')->nullable();
            $table->integer('member_id');
            $table->integer('role_id');

            $table->index('inherited_from', 'index_member_roles_on_inherited_from');
            $table->index('member_id', 'index_member_roles_on_member_id');
            $table->index('role_id', 'index_member_roles_on_role_id');
        });

        Schema::create('groups_users', function (Blueprint $table) {
            $table->integer('group_id');
            $table->integer('user_id');

            $table->unique(['group_id', 'user_id'], 'groups_users_ids');
        });

        Schema::create('roles_managed_roles', function (Blueprint $table) {
            $table->integer('managed_role_id');
            $table->integer('role_id');

            $table->unique(
                ['role_id', 'managed_role_id'],
                'index_roles_managed_roles_on_role_id_and_managed_role_id',
            );
        });

        Schema::create('enabled_modules', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->string('name');
            $table->integer('project_id')->nullable();

            $table->index('project_id', 'enabled_modules_project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enabled_modules');
        Schema::dropIfExists('roles_managed_roles');
        Schema::dropIfExists('groups_users');
        Schema::dropIfExists('member_roles');
        Schema::dropIfExists('members');
    }
};
