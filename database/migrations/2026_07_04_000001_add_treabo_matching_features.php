<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('treabo_response_settings') && !Schema::hasColumn('treabo_response_settings', 'free_per_task_limit')) {
            Schema::table('treabo_response_settings', function (Blueprint $table) {
                $table->unsignedInteger('free_per_task_limit')->default(5)->after('free_daily_limit');
            });
        }

        if (!Schema::hasTable('treabo_matching_settings')) {
            Schema::create('treabo_matching_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('category_weight')->default(30);
                $table->unsignedInteger('work_weight')->default(25);
                $table->unsignedInteger('rating_weight')->default(20);
                $table->unsignedInteger('reviews_weight')->default(10);
                $table->unsignedInteger('online_weight')->default(10);
                $table->unsignedInteger('profile_relevance_weight')->default(5);
                $table->decimal('min_rating', 3, 1)->default(0);
                $table->unsignedInteger('min_reviews')->default(0);
                $table->unsignedInteger('max_recommended')->default(5);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('proffi_task_recommended_specialists')) {
            Schema::create('proffi_task_recommended_specialists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('proffi_tasks')->cascadeOnDelete();
                $table->foreignId('specialist_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('score', 10, 4)->default(0);
                $table->unsignedTinyInteger('rank')->default(1);
                $table->timestamps();
                $table->unique(['task_id', 'specialist_id']);
                $table->index(['task_id', 'rank']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_task_recommended_specialists');
        Schema::dropIfExists('treabo_matching_settings');

        if (Schema::hasTable('treabo_response_settings') && Schema::hasColumn('treabo_response_settings', 'free_per_task_limit')) {
            Schema::table('treabo_response_settings', function (Blueprint $table) {
                $table->dropColumn('free_per_task_limit');
            });
        }
    }
};
