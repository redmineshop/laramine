<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Redmine 7.0.1 `settings` rows. Name/value pairs such as `display_subprojects_issues`.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->integer('id', autoIncrement: true);
            $table->string('name')->default('');
            $table->dateTime('updated_on')->nullable();
            $table->text('value')->nullable();

            $table->index('name', 'index_settings_on_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
