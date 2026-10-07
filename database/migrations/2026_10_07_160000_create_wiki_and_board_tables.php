<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 wiki and forum tables.
     *
     * Column nullability, defaults, and index names follow the structure dump.
     * These tables have no foreign keys in that dump.
     */
    public function up(): void
    {
        Schema::create('wikis', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('project_id');
            $table->string('start_page', 255);
            $table->integer('status')->default(1);

            $table->index('project_id', 'wikis_project_id');
        });

        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on');
            $table->integer('parent_id')->nullable();
            $table->boolean('protected')->default(false);
            $table->string('title', 255);
            $table->integer('wiki_id');

            $table->index('parent_id', 'index_wiki_pages_on_parent_id');
            $table->index(['wiki_id', 'title'], 'wiki_pages_wiki_id_title');
            $table->index('wiki_id', 'index_wiki_pages_on_wiki_id');
        });

        Schema::create('wiki_contents', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('author_id')->nullable();
            $table->string('comments', 1024)->nullable()->default('');
            $table->integer('page_id');
            $table->text('text')->nullable();
            $table->dateTime('updated_on');
            $table->integer('version');

            $table->index('author_id', 'index_wiki_contents_on_author_id');
            $table->index('page_id', 'wiki_contents_page_id');
        });

        Schema::create('wiki_content_versions', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('author_id')->nullable();
            $table->string('comments', 1024)->nullable()->default('');
            $table->string('compression', 6)->nullable()->default('');
            $table->binary('data')->nullable();
            $table->integer('page_id');
            $table->dateTime('updated_on');
            $table->integer('version');
            $table->integer('wiki_content_id');

            $table->index('updated_on', 'index_wiki_content_versions_on_updated_on');
            $table->index('wiki_content_id', 'wiki_content_versions_wcid');
        });

        Schema::create('wiki_redirects', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->dateTime('created_on');
            $table->string('redirects_to')->nullable();
            $table->integer('redirects_to_wiki_id');
            $table->string('title')->nullable();
            $table->integer('wiki_id');

            $table->index(['wiki_id', 'title'], 'wiki_redirects_wiki_id_title');
            $table->index('wiki_id', 'index_wiki_redirects_on_wiki_id');
        });

        Schema::create('boards', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->string('description')->nullable();
            $table->integer('last_message_id')->nullable();
            $table->integer('messages_count')->default(0);
            $table->string('name')->default('');
            $table->integer('parent_id')->nullable();
            $table->integer('position')->nullable();
            $table->integer('project_id');
            $table->integer('topics_count')->default(0);

            $table->index('last_message_id', 'index_boards_on_last_message_id');
            $table->index('project_id', 'boards_project_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('author_id')->nullable();
            $table->integer('board_id');
            $table->text('content')->nullable();
            $table->dateTime('created_on');
            $table->integer('last_reply_id')->nullable();
            $table->boolean('locked')->nullable()->default(false);
            $table->integer('parent_id')->nullable();
            $table->integer('replies_count')->default(0);
            $table->integer('sticky')->nullable()->default(0);
            $table->string('subject')->default('');
            $table->dateTime('updated_on');

            $table->index('author_id', 'index_messages_on_author_id');
            $table->index('board_id', 'messages_board_id');
            $table->index('created_on', 'index_messages_on_created_on');
            $table->index('last_reply_id', 'index_messages_on_last_reply_id');
            $table->index('parent_id', 'messages_parent_id');
        });
    }

    /**
     * Reverse the wiki and forum tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('boards');
        Schema::dropIfExists('wiki_redirects');
        Schema::dropIfExists('wiki_content_versions');
        Schema::dropIfExists('wiki_contents');
        Schema::dropIfExists('wiki_pages');
        Schema::dropIfExists('wikis');
    }
};
