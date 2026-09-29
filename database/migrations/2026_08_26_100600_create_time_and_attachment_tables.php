<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 time entries, attachments, and comments.
     *
     * `attachments.filesize` is an 8-byte integer in the dump (MySQL BIGINT).
     */
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('activity_id');
            $table->integer('author_id')->nullable();
            $table->string('comments', 1024)->nullable();
            $table->dateTime('created_on');
            $table->float('hours', 0);
            $table->integer('issue_id')->nullable();
            $table->integer('project_id');
            $table->date('spent_on');
            $table->integer('tmonth');
            $table->integer('tweek');
            $table->integer('tyear');
            $table->dateTime('updated_on');
            $table->integer('user_id');

            $table->index('activity_id', 'index_time_entries_on_activity_id');
            $table->index('created_on', 'index_time_entries_on_created_on');
            $table->index('issue_id', 'time_entries_issue_id');
            $table->index('project_id', 'time_entries_project_id');
            $table->index('user_id', 'index_time_entries_on_user_id');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('author_id')->default(0);
            $table->integer('container_id')->nullable();
            $table->string('container_type', 30)->nullable();
            $table->string('content_type')->nullable();
            $table->dateTime('created_on')->nullable();
            $table->string('description')->nullable();
            $table->string('digest', 64)->nullable();
            $table->string('disk_directory')->nullable();
            $table->string('disk_filename')->default('');
            $table->integer('downloads')->default(0);
            $table->string('filename')->default('');
            $table->bigInteger('filesize')->default(0);

            $table->index('author_id', 'index_attachments_on_author_id');
            $table->index(
                ['container_id', 'container_type'],
                'index_attachments_on_container_id_and_container_type',
            );
            $table->index('created_on', 'index_attachments_on_created_on');
            $table->index('disk_filename', 'index_attachments_on_disk_filename');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('author_id')->default(0);
            $table->integer('commented_id')->default(0);
            $table->string('commented_type', 30)->default('');
            $table->text('content')->nullable();
            $table->dateTime('created_on');
            $table->dateTime('updated_on');

            $table->index('author_id', 'index_comments_on_author_id');
            $table->index(
                ['commented_id', 'commented_type'],
                'index_comments_on_commented_id_and_commented_type',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('time_entries');
    }
};
