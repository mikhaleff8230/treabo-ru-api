<?php

namespace Database\Seeders;

use App\Models\CategoryAttribute;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ProffiConstructionCategoryAttributesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'id' => 'bathroom-renovation',
                'name_ru' => 'Ремонт ванной',
                'icon' => 'Bath',
                'sort_order' => 10,
                'attributes' => [
                    ['code' => 'area', 'name_ru' => 'Площадь', 'type' => 'number', 'unit' => 'м2', 'required' => true, 'ai_priority' => 100],
                    ['code' => 'demolition', 'name_ru' => 'Нужен демонтаж', 'type' => 'boolean', 'ai_priority' => 70],
                    ['code' => 'materials_available', 'name_ru' => 'Материалы есть', 'type' => 'boolean', 'ai_priority' => 60],
                    ['code' => 'plumbing_replace', 'name_ru' => 'Замена сантехники', 'type' => 'boolean', 'ai_priority' => 70],
                    ['code' => 'tile_work', 'name_ru' => 'Плиточные работы', 'type' => 'boolean', 'ai_priority' => 70],
                    ['code' => 'start_time', 'name_ru' => 'Когда начать', 'type' => 'select', 'options' => ['срочно', 'в течение недели', 'в течение месяца', 'пока узнаю цену'], 'ai_priority' => 80],
                    ['code' => 'photos', 'name_ru' => 'Фото объекта', 'type' => 'file', 'ai_priority' => 90],
                ],
            ],
            [
                'id' => 'tile-work',
                'name_ru' => 'Плиточные работы',
                'icon' => 'Grid2X2',
                'sort_order' => 20,
                'attributes' => [
                    ['code' => 'area', 'name_ru' => 'Площадь', 'type' => 'number', 'unit' => 'м2', 'required' => true, 'ai_priority' => 100],
                    ['code' => 'surface_type', 'name_ru' => 'Поверхность', 'type' => 'select', 'options' => ['пол', 'стены', 'пол и стены'], 'ai_priority' => 90],
                    ['code' => 'old_tile_removal', 'name_ru' => 'Снять старую плитку', 'type' => 'boolean', 'ai_priority' => 70],
                    ['code' => 'tile_available', 'name_ru' => 'Плитка куплена', 'type' => 'boolean', 'ai_priority' => 60],
                    ['code' => 'object_type', 'name_ru' => 'Объект', 'type' => 'select', 'options' => ['ванная', 'кухня', 'коридор', 'другое'], 'ai_priority' => 80],
                    ['code' => 'photos', 'name_ru' => 'Фото объекта', 'type' => 'file', 'ai_priority' => 90],
                ],
            ],
            [
                'id' => 'plumbing',
                'name_ru' => 'Сантехника',
                'icon' => 'Wrench',
                'sort_order' => 30,
                'attributes' => [
                    ['code' => 'work_type', 'name_ru' => 'Тип работ', 'type' => 'select', 'options' => ['ремонт', 'установка', 'замена', 'авария'], 'ai_priority' => 100],
                    ['code' => 'object_type', 'name_ru' => 'Объект', 'type' => 'select', 'options' => ['кран', 'унитаз', 'ванна', 'душевая', 'трубы', 'бойлер', 'другое'], 'ai_priority' => 90],
                    ['code' => 'urgent', 'name_ru' => 'Срочно', 'type' => 'boolean', 'ai_priority' => 80],
                    ['code' => 'materials_available', 'name_ru' => 'Материалы есть', 'type' => 'boolean', 'ai_priority' => 60],
                    ['code' => 'photos', 'name_ru' => 'Фото объекта', 'type' => 'file', 'ai_priority' => 90],
                ],
            ],
            [
                'id' => 'electrical',
                'name_ru' => 'Электрика',
                'icon' => 'Zap',
                'sort_order' => 40,
                'attributes' => [
                    ['code' => 'work_type', 'name_ru' => 'Тип работ', 'type' => 'select', 'options' => ['ремонт', 'монтаж', 'замена', 'диагностика'], 'ai_priority' => 100],
                    ['code' => 'object_type', 'name_ru' => 'Объект', 'type' => 'select', 'options' => ['розетки', 'проводка', 'щиток', 'свет', 'другое'], 'ai_priority' => 90],
                    ['code' => 'urgent', 'name_ru' => 'Срочно', 'type' => 'boolean', 'ai_priority' => 80],
                    ['code' => 'property_type', 'name_ru' => 'Тип помещения', 'type' => 'select', 'options' => ['квартира', 'дом', 'офис', 'коммерция'], 'ai_priority' => 70],
                    ['code' => 'photos', 'name_ru' => 'Фото объекта', 'type' => 'file', 'ai_priority' => 90],
                ],
            ],
            [
                'id' => 'air-conditioners',
                'name_ru' => 'Кондиционеры',
                'icon' => 'Fan',
                'sort_order' => 50,
                'attributes' => [
                    ['code' => 'work_type', 'name_ru' => 'Тип работ', 'type' => 'select', 'options' => ['установка', 'ремонт', 'обслуживание', 'демонтаж'], 'ai_priority' => 100],
                    ['code' => 'conditioner_type', 'name_ru' => 'Тип кондиционера', 'type' => 'select', 'options' => ['настенный', 'кассетный', 'напольный', 'не знаю'], 'ai_priority' => 90],
                    ['code' => 'blocks_count', 'name_ru' => 'Количество блоков', 'type' => 'number', 'unit' => 'шт', 'ai_priority' => 70],
                    ['code' => 'floor', 'name_ru' => 'Этаж', 'type' => 'number', 'ai_priority' => 60],
                    ['code' => 'urgent', 'name_ru' => 'Срочно', 'type' => 'boolean', 'ai_priority' => 80],
                    ['code' => 'photos', 'name_ru' => 'Фото объекта', 'type' => 'file', 'ai_priority' => 90],
                ],
            ],
        ];

        foreach ($categories as $categoryIndex => $categoryData) {
            $category = ServiceCategory::updateOrCreate(
                ['id' => $categoryData['id']],
                [
                    'parent_id' => null,
                    'slug' => $categoryData['id'],
                    'icon' => $categoryData['icon'],
                    'name_ru' => $categoryData['name_ru'],
                    'name_ro' => $categoryData['name_ru'],
                    'is_active' => true,
                    'sort_order' => $categoryData['sort_order'],
                ]
            );

            foreach ($categoryData['attributes'] as $attributeIndex => $attributeData) {
                CategoryAttribute::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'code' => $attributeData['code'],
                    ],
                    [
                        'name_ru' => $attributeData['name_ru'],
                        'name_ro' => $attributeData['name_ru'],
                        'type' => $attributeData['type'],
                        'unit' => $attributeData['unit'] ?? null,
                        'required' => (bool) ($attributeData['required'] ?? false),
                        'ai_priority' => (int) ($attributeData['ai_priority'] ?? 0),
                        'show_in_form' => true,
                        'show_to_master' => true,
                        'sort_order' => ($categoryIndex * 100) + $attributeIndex + 1,
                        'help_text_ru' => null,
                        'help_text_ro' => null,
                        'options' => $attributeData['options'] ?? null,
                        'validation_rules' => null,
                    ]
                );
            }
        }
    }
}
