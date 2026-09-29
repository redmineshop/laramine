<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 OAuth tables (Doorkeeper-shaped columns).
     *
     * Datetime columns follow the dump default precision (fractional seconds),
     * unlike Redmine columns marked `precision: nil`.
     */
    public function up(): void
    {
        Schema::create('oauth_applications', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->boolean('confidential')->default(true);
            $table->dateTime('created_at', 6);
            $table->string('name');
            $table->text('redirect_uri');
            $table->text('scopes');
            $table->string('secret');
            $table->string('uid');
            $table->dateTime('updated_at', 6);

            $table->unique('uid', 'index_oauth_applications_on_uid');
        });

        Schema::create('oauth_access_grants', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('application_id');
            $table->string('code_challenge')->nullable();
            $table->string('code_challenge_method')->nullable();
            $table->dateTime('created_at', 6);
            $table->integer('expires_in');
            $table->text('redirect_uri');
            $table->integer('resource_owner_id');
            $table->dateTime('revoked_at', 6)->nullable();
            $table->text('scopes')->nullable();
            $table->string('token');

            $table->index('application_id', 'index_oauth_access_grants_on_application_id');
            $table->unique('token', 'index_oauth_access_grants_on_token');
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('application_id')->nullable();
            $table->dateTime('created_at', 6);
            $table->integer('expires_in')->nullable();
            $table->string('previous_refresh_token')->default('');
            $table->string('refresh_token')->nullable();
            $table->integer('resource_owner_id')->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->text('scopes')->nullable();
            $table->string('token');

            $table->index('application_id', 'index_oauth_access_tokens_on_application_id');
            $table->unique('refresh_token', 'index_oauth_access_tokens_on_refresh_token');
            $table->index('resource_owner_id', 'index_oauth_access_tokens_on_resource_owner_id');
            $table->unique('token', 'index_oauth_access_tokens_on_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_access_grants');
        Schema::dropIfExists('oauth_applications');
    }
};
