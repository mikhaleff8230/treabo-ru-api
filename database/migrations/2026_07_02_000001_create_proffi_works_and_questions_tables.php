<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('proffi_works')) {
            Schema::create('proffi_works', function (Blueprint $table) {
                $table->id();
                $table->string('category_id', 64)->nullable()->index();
                $table->string('title');
                $table->string('slug', 128)->nullable()->index();
                $table->json('aliases')->nullable();
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->foreign('category_id')
                    ->references('id')
                    ->on('proffi_categories')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('proffi_work_questions')) {
            Schema::create('proffi_work_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_id')->constrained('proffi_works')->cascadeOnDelete();
                $table->text('question');
                $table->string('field_key', 128)->nullable()->index();
                $table->enum('type', ['text', 'textarea', 'number', 'yesno', 'select', 'multiselect', 'photo']);
                $table->json('options')->nullable();
                $table->string('placeholder')->nullable();
                $table->text('help_text')->nullable();
                $table->boolean('is_required')->default(false);
                $table->integer('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_work_questions');
        Schema::dropIfExists('proffi_works');
    }
};
