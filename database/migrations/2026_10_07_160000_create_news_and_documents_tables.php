<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 `news` and `documents` tables.
     *
     * Columns follow the structure dump. Defaults of 0 stay without foreign keys.
     * These tables are outside the P0 layout compare.
     */
    public function up(): void
    {
        Schema::create('news', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('author_id')->default(0);
            $table->integer('comments_count')->default(0);
            $table->dateTime('created_on')->nullable();
            $table->text('description')->nullable();
            $table->integer('project_id')->nullable();
            $table->string('summary', 255)->default('');
            $table->string('title', 60)->default('');

            $table->index('author_id', 'index_news_on_author_id');
            $table->index('created_on', 'index_news_on_created_on');
            $table->index('project_id', 'news_project_id');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->integer('category_id')->default(0);
            $table->dateTime('created_on')->nullable();
            $table->text('description')->nullable();
            $table->integer('project_id')->default(0);
            $table->string('title')->default('');

            $table->index('category_id', 'index_documents_on_category_id');
            $table->index('created_on', 'index_documents_on_created_on');
            $table->index('project_id', 'documents_project_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('news');
    }
};
