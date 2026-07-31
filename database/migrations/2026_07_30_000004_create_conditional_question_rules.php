<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proffi_question_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('proffi_works')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['work_id', 'sort_order']);
        });

        Schema::table('proffi_work_questions', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('work_id')
                ->constrained('proffi_question_groups')->nullOnDelete();
            $table->enum('default_visibility', ['always', 'conditional'])
                ->default('always')->after('is_required');
            $table->boolean('is_safety_critical')->default(false)->after('default_visibility');
            $table->text('ai_instruction')->nullable()->after('help_text');
            $table->index(['work_id', 'default_visibility', 'is_active'], 'work_questions_visibility_idx');
        });

        Schema::create('proffi_question_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained('proffi_works')->cascadeOnDelete();
            $table->string('name');
            $table->enum('match_type', ['all', 'any'])->default('all');
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['work_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_question_rules');
        Schema::table('proffi_work_questions', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropIndex('work_questions_visibility_idx');
            $table->dropColumn(['group_id', 'default_visibility', 'is_safety_critical', 'ai_instruction']);
        });
        Schema::dropIfExists('proffi_question_groups');
    }
};
