<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 `users` plus Laravel session tables.
     *
     * Primary key is a signed integer, matching the Rails dump (not bigint).
     * Email lives on `email_addresses`. Password reset tokens stay a framework table.
     */
    public function up(): void
    {
        // Created before `users` so `auth_source_id` can be a foreign key on the
        // original table. Altering `users` later drops the lower(login) expression index on SQLite.
        Schema::create('auth_sources', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->string('account')->nullable();
            $table->string('account_password')->default('')->nullable();
            $table->string('attr_firstname', 30)->nullable();
            $table->string('attr_lastname', 30)->nullable();
            $table->string('attr_login', 30)->nullable();
            $table->string('attr_mail', 30)->nullable();
            $table->string('base_dn', 255)->nullable();
            $table->text('filter')->nullable();
            $table->string('host', 60)->nullable();
            $table->string('name', 60)->default('');
            $table->boolean('onthefly_register')->default(false);
            $table->integer('port')->nullable();
            $table->integer('timeout')->nullable();
            $table->boolean('tls')->default(false);
            $table->string('type', 30)->default('');
            $table->boolean('verify_peer')->default(true);

            $table->index(['id', 'type'], 'index_auth_sources_on_id_and_type');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('admin')->default(false);
            $table->integer('auth_source_id')->nullable();
            $table->dateTime('created_on')->nullable();
            $table->string('firstname', 30)->default('');
            $table->string('hashed_password', 40)->default('');
            $table->string('language', 5)->default('')->nullable();
            $table->dateTime('last_login_on')->nullable();
            $table->string('lastname', 255)->default('');
            $table->string('login')->default('');
            $table->string('mail_notification')->default('');
            $table->boolean('must_change_passwd')->default(false);
            $table->dateTime('passwd_changed_on')->nullable();
            $table->string('salt', 64)->nullable();
            $table->integer('status')->default(1);
            $table->boolean('twofa_required')->default(false)->nullable();
            $table->string('twofa_scheme')->nullable();
            $table->string('twofa_totp_key')->nullable();
            $table->integer('twofa_totp_last_used_at')->nullable();
            $table->string('type')->nullable();
            $table->dateTime('updated_on')->nullable();

            $table->index('auth_source_id', 'index_users_on_auth_source_id');
            $table->foreign('auth_source_id')->references('id')->on('auth_sources');
            $table->index(['id', 'type'], 'index_users_on_id_and_type');
            $table->index('login', 'index_users_on_login');
            $table->index('type', 'index_users_on_type');

            // Dump index is an expression on lower(login). MySQL 8 needs a functional index.
            $driver = Schema::getConnection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->rawIndex('(lower(`login`))', 'index_users_on_lower_login');
            } else {
                $table->rawIndex('lower(login)', 'index_users_on_lower_login');
            }
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->integer('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('auth_sources');
    }
};
