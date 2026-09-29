<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database foreign keys for P0 references that can accept a real row id.
     *
     * Skipped on purpose, matching Redmine's sentinel 0 and the 7.0.1 dump:
     * columns declared `null: false, default: 0`, and polymorphic id columns.
     * Those links stay application-level so an ETL load of 0 does not fail.
     *
     * The four OAuth foreign keys are the only ones declared in the dump.
     */
    public function up(): void
    {
        Schema::table('email_addresses', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->foreign('default_time_entry_activity_id')->references('id')->on('enumerations');
        });

        Schema::table('enumerations', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('enumerations');
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('projects');
            $table->foreign('default_assigned_to_id')->references('id')->on('users');
            $table->foreign('default_version_id')->references('id')->on('versions');
            $table->foreign('default_issue_query_id')->references('id')->on('queries');
        });

        Schema::table('issue_categories', function (Blueprint $table) {
            $table->foreign('assigned_to_id')->references('id')->on('users');
        });

        Schema::table('enabled_modules', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('trackers', function (Blueprint $table) {
            $table->foreign('default_status_id')->references('id')->on('issue_statuses');
        });

        Schema::table('member_roles', function (Blueprint $table) {
            $table->foreign('member_id')->references('id')->on('members');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('inherited_from')->references('id')->on('member_roles');
        });

        Schema::table('groups_users', function (Blueprint $table) {
            $table->foreign('group_id')->references('id')->on('users');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('roles_managed_roles', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('managed_role_id')->references('id')->on('roles');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('tracker_id')->references('id')->on('trackers');
            $table->foreign('status_id')->references('id')->on('issue_statuses');
            $table->foreign('priority_id')->references('id')->on('enumerations');
            $table->foreign('author_id')->references('id')->on('users');
            $table->foreign('assigned_to_id')->references('id')->on('users');
            $table->foreign('category_id')->references('id')->on('issue_categories');
            $table->foreign('fixed_version_id')->references('id')->on('versions');
            $table->foreign('parent_id')->references('id')->on('issues');
            $table->foreign('root_id')->references('id')->on('issues');
        });

        Schema::table('issue_relations', function (Blueprint $table) {
            $table->foreign('issue_from_id')->references('id')->on('issues');
            $table->foreign('issue_to_id')->references('id')->on('issues');
        });

        Schema::table('watchers', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->foreign('updated_by_id')->references('id')->on('users');
        });

        Schema::table('reactions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('custom_fields_roles', function (Blueprint $table) {
            $table->foreign('custom_field_id')->references('id')->on('custom_fields');
            $table->foreign('role_id')->references('id')->on('roles');
        });

        Schema::table('custom_field_enumerations', function (Blueprint $table) {
            $table->foreign('custom_field_id')->references('id')->on('custom_fields');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
            $table->foreign('issue_id')->references('id')->on('issues');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('author_id')->references('id')->on('users');
            $table->foreign('activity_id')->references('id')->on('enumerations');
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects');
        });

        Schema::table('queries_roles', function (Blueprint $table) {
            $table->foreign('query_id')->references('id')->on('queries');
            $table->foreign('role_id')->references('id')->on('roles');
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('import_items', function (Blueprint $table) {
            $table->foreign('import_id')->references('id')->on('imports');
        });

        Schema::table('oauth_access_grants', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('oauth_applications');
            $table->foreign('resource_owner_id')->references('id')->on('users');
        });

        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('oauth_applications');
            $table->foreign('resource_owner_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['resource_owner_id']);
        });

        Schema::table('oauth_access_grants', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['resource_owner_id']);
        });

        Schema::table('import_items', function (Blueprint $table) {
            $table->dropForeign(['import_id']);
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('queries_roles', function (Blueprint $table) {
            $table->dropForeign(['query_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('queries', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['issue_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['author_id']);
            $table->dropForeign(['activity_id']);
        });

        Schema::table('custom_field_enumerations', function (Blueprint $table) {
            $table->dropForeign(['custom_field_id']);
        });

        Schema::table('custom_fields_roles', function (Blueprint $table) {
            $table->dropForeign(['custom_field_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('reactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->dropForeign(['updated_by_id']);
        });

        Schema::table('watchers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('issue_relations', function (Blueprint $table) {
            $table->dropForeign(['issue_from_id']);
            $table->dropForeign(['issue_to_id']);
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['tracker_id']);
            $table->dropForeign(['status_id']);
            $table->dropForeign(['priority_id']);
            $table->dropForeign(['author_id']);
            $table->dropForeign(['assigned_to_id']);
            $table->dropForeign(['category_id']);
            $table->dropForeign(['fixed_version_id']);
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['root_id']);
        });

        Schema::table('roles_managed_roles', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['managed_role_id']);
        });

        Schema::table('groups_users', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('member_roles', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['inherited_from']);
        });

        Schema::table('trackers', function (Blueprint $table) {
            $table->dropForeign(['default_status_id']);
        });

        Schema::table('enabled_modules', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::table('issue_categories', function (Blueprint $table) {
            $table->dropForeign(['assigned_to_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['default_assigned_to_id']);
            $table->dropForeign(['default_version_id']);
            $table->dropForeign(['default_issue_query_id']);
        });

        Schema::table('enumerations', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['project_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['default_time_entry_activity_id']);
        });

        Schema::table('email_addresses', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
