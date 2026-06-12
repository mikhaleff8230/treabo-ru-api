<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_chat_knowledge')) {
            Schema::create('ai_chat_knowledge', function (Blueprint $table) {
                $table->id();
                $table->enum('type', ['category', 'work', 'parameter', 'question', 'instruction'])->index();
                $table->string('category_slug', 128)->nullable()->index();
                $table->string('work_slug', 128)->nullable()->index();
                $table->string('title');
                $table->string('slug', 160)->nullable()->index();
                $table->text('content')->nullable();
                $table->json('payload')->nullable();
                $table->integer('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['type', 'is_active', 'sort_order']);
            });
        }

        if (DB::table('ai_chat_knowledge')->count() === 0) {
            DB::table('ai_chat_knowledge')->insert([
                [
                    'type' => 'category',
                    'category_slug' => 'bathroom-renovation',
                    'work_slug' => null,
                    'title' => 'Ремонт ванной комнаты',
                    'slug' => 'bathroom-renovation',
                    'content' => 'Работы по ванной: плитка, сантехника, демонтаж, подготовка стен и пола, электрика, вентиляция.',
                    'payload' => json_encode(['aliases' => ['ванна', 'ванная', 'санузел', 'baie']], JSON_UNESCAPED_UNICODE),
                    'sort_order' => 10,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'work',
                    'category_slug' => 'bathroom-renovation',
                    'work_slug' => 'tile-work',
                    'title' => 'Укладка плитки',
                    'slug' => 'tile-work',
                    'content' => 'Уточнять площадь, демонтаж старой плитки, состояние стен, наличие материалов и фото помещения.',
                    'payload' => json_encode(['maps_to_category' => 'tile-work'], JSON_UNESCAPED_UNICODE),
                    'sort_order' => 20,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'parameter',
                    'category_slug' => 'bathroom-renovation',
                    'work_slug' => 'tile-work',
                    'title' => 'Площадь помещения',
                    'slug' => 'area',
                    'content' => 'Площадь ванной или площадь укладки плитки в квадратных метрах.',
                    'payload' => json_encode(['field' => 'area', 'unit' => 'm2', 'required' => true], JSON_UNESCAPED_UNICODE),
                    'sort_order' => 30,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'question',
                    'category_slug' => 'bathroom-renovation',
                    'work_slug' => 'tile-work',
                    'title' => 'Материалы куплены?',
                    'slug' => 'materials-ready',
                    'content' => 'Если пользователь не указал материалы, спросить: материалы уже куплены или мастер должен помочь с подбором?',
                    'payload' => json_encode(['field' => 'materials_ready'], JSON_UNESCAPED_UNICODE),
                    'sort_order' => 40,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'type' => 'instruction',
                    'category_slug' => null,
                    'work_slug' => null,
                    'title' => 'Не публиковать автоматически',
                    'slug' => 'draft-only',
                    'content' => 'AI только готовит черновик и уточняющие вопросы. Публикация заявки происходит только после подтверждения пользователя.',
                    'payload' => null,
                    'sort_order' => 50,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_knowledge');
    }
};
