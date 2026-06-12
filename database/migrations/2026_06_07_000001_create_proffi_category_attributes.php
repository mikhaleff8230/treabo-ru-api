<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_categories', 'parent_id')) {
                $table->string('parent_id', 64)->nullable()->after('id')->index();
            }
            if (!Schema::hasColumn('proffi_categories', 'slug')) {
                $table->string('slug', 128)->nullable()->unique()->after('name_ro');
            }
            if (!Schema::hasColumn('proffi_categories', 'is_active')) {
                $table->boolean('is_active')->default(true)->index()->after('slug');
            }
            if (!Schema::hasColumn('proffi_categories', 'sort_order')) {
                $table->integer('sort_order')->default(0)->index()->after('is_active');
            }
        });

        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_tasks', 'category_id')) {
                $table->string('category_id', 64)->nullable()->after('category')->index();
                $table->foreign('category_id')->references('id')->on('proffi_categories')->nullOnDelete();
            }
        });

        if (!Schema::hasTable('category_attributes')) {
            Schema::create('category_attributes', function (Blueprint $table) {
                $table->id();
                $table->string('category_id', 64);
                $table->string('code', 128);
                $table->string('name_ru');
                $table->string('name_ro')->nullable();
                $table->enum('type', ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'date', 'file']);
                $table->string('unit', 32)->nullable();
                $table->boolean('required')->default(false);
                $table->integer('ai_priority')->default(0);
                $table->boolean('show_in_form')->default(true);
                $table->boolean('show_to_master')->default(true);
                $table->integer('sort_order')->default(0);
                $table->text('help_text_ru')->nullable();
                $table->text('help_text_ro')->nullable();
                $table->json('options')->nullable();
                $table->json('validation_rules')->nullable();
                $table->timestamps();

                $table->unique(['category_id', 'code']);
                $table->index(['category_id', 'sort_order']);
                $table->foreign('category_id')->references('id')->on('proffi_categories')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('job_attribute_values')) {
            Schema::create('job_attribute_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained('proffi_tasks')->cascadeOnDelete();
                $table->foreignId('category_attribute_id')->constrained('category_attributes')->cascadeOnDelete();
                $table->text('value_text')->nullable();
                $table->decimal('value_number', 14, 4)->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->json('value_json')->nullable();
                $table->timestamps();

                $table->unique(['job_id', 'category_attribute_id']);
                $table->index(['job_id', 'category_attribute_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_attribute_values');
        Schema::dropIfExists('category_attributes');

        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_tasks', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
        });

        Schema::table('proffi_categories', function (Blueprint $table) {
            foreach (['parent_id', 'slug', 'is_active', 'sort_order'] as $column) {
                if (Schema::hasColumn('proffi_categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
