<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 identity tables other than `users` and `auth_sources`.
     *
     * `auth_sources` is created with `users` so the auth-source foreign key
     * does not rebuild the `lower(login)` expression index.
     * Other foreign keys that are safe to enforce are added in a later migration.
     */
    public function up(): void
    {
        Schema::create('email_addresses', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->string('address');
            $table->dateTime('created_on');
            $table->boolean('is_default')->default(false);
            $table->boolean('notify')->default(true);
            $table->dateTime('updated_on');
            $table->integer('user_id');

            $table->index('user_id', 'index_email_addresses_on_user_id');
        });

        Schema::create('tokens', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->string('action', 30)->default('');
            $table->dateTime('created_on');
            $table->dateTime('updated_on')->nullable();
            $table->integer('user_id')->default(0);
            $table->string('value', 40)->default('');

            $table->index('user_id', 'index_tokens_on_user_id');
            $table->unique('value', 'tokens_value');
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('hide_mail')->default(true)->nullable();
            $table->text('others')->nullable();
            $table->string('time_zone')->nullable();
            $table->integer('user_id')->default(0);

            $table->index('user_id', 'index_user_preferences_on_user_id');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('all_roles_managed')->default(true);
            $table->boolean('assignable')->default(true)->nullable();
            $table->integer('builtin')->default(0);
            $table->integer('default_time_entry_activity_id')->nullable();
            $table->string('issues_visibility', 30)->default('default');
            $table->string('name', 255)->default('')->nullable();
            $table->text('permissions')->nullable();
            $table->integer('position')->nullable();
            $table->text('settings')->nullable();
            $table->string('time_entries_visibility', 30)->default('all');
            $table->string('users_visibility', 30)->default('members_of_visible_projects');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('tokens');
        Schema::dropIfExists('email_addresses');
    }
};
