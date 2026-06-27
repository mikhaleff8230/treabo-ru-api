<?php

namespace App\Console\Commands;

use App\Models\ProffiCategory;
use Illuminate\Console\Command;

class EnsureTreaboRuCategories extends Command
{
    protected $signature = 'treabo:ensure-ru-categories {--deactivate-missing : Deactivate categories that are not in the base RU set}';

    protected $description = 'Create or update the base Russian Treabo service categories without touching tasks or users';

    public function handle(): int
    {
        $categories = $this->categories();
        $baseIds = collect($categories)->pluck('id')->all();

        foreach ($categories as $index => $category) {
            ProffiCategory::updateOrCreate(
                ['id' => $category['id']],
                [
                    'parent_id' => $category['parent_id'] ?? null,
                    'icon' => $category['icon'] ?? 'MoreHorizontal',
                    'name_ru' => $category['name_ru'],
                    'name_ro' => $category['name_ru'],
                    'slug' => $category['slug'] ?? $category['id'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'] ?? (($index + 1) * 10),
                ],
            );
        }

        if ($this->option('deactivate-missing')) {
            ProffiCategory::whereNotIn('id', $baseIds)->update(['is_active' => false]);
        }

        $this->info('Base RU Treabo categories are ready: ' . count($categories));
        return self::SUCCESS;
    }

    private function categories(): array
    {
        return [
            ['id' => 'repair', 'slug' => 'repair', 'icon' => 'Hammer', 'name_ru' => 'Ремонт и строительство', 'sort_order' => 10],
            ['id' => 'tile-work', 'slug' => 'tile-work', 'parent_id' => 'repair', 'icon' => 'Grid3X3', 'name_ru' => 'Укладка плитки', 'sort_order' => 20],
            ['id' => 'bathroom-renovation', 'slug' => 'bathroom-renovation', 'parent_id' => 'repair', 'icon' => 'Bath', 'name_ru' => 'Ремонт ванной', 'sort_order' => 30],
            ['id' => 'apartment-renovation', 'slug' => 'apartment-renovation', 'parent_id' => 'repair', 'icon' => 'Home', 'name_ru' => 'Ремонт квартиры', 'sort_order' => 40],
            ['id' => 'finishing', 'slug' => 'finishing', 'parent_id' => 'repair', 'icon' => 'Paintbrush', 'name_ru' => 'Отделочные работы', 'sort_order' => 50],
            ['id' => 'plumbing', 'slug' => 'plumbing', 'parent_id' => 'repair', 'icon' => 'Wrench', 'name_ru' => 'Сантехника', 'sort_order' => 60],
            ['id' => 'electrical', 'slug' => 'electrical', 'parent_id' => 'repair', 'icon' => 'Zap', 'name_ru' => 'Электрика', 'sort_order' => 70],
            ['id' => 'windows-doors', 'slug' => 'windows-doors', 'parent_id' => 'repair', 'icon' => 'DoorOpen', 'name_ru' => 'Окна и двери', 'sort_order' => 80],

            ['id' => 'home-services', 'slug' => 'home-services', 'icon' => 'House', 'name_ru' => 'Дом и быт', 'sort_order' => 100],
            ['id' => 'cleaning', 'slug' => 'cleaning', 'parent_id' => 'home-services', 'icon' => 'Sparkles', 'name_ru' => 'Уборка', 'sort_order' => 110],
            ['id' => 'furniture-assembly', 'slug' => 'furniture-assembly', 'parent_id' => 'home-services', 'icon' => 'PackageCheck', 'name_ru' => 'Сборка мебели', 'sort_order' => 120],
            ['id' => 'moving', 'slug' => 'moving', 'parent_id' => 'home-services', 'icon' => 'Truck', 'name_ru' => 'Переезды и грузчики', 'sort_order' => 130],
            ['id' => 'appliance-repair', 'slug' => 'appliance-repair', 'parent_id' => 'home-services', 'icon' => 'Settings', 'name_ru' => 'Ремонт техники', 'sort_order' => 140],
            ['id' => 'air-conditioners', 'slug' => 'air-conditioners', 'parent_id' => 'home-services', 'icon' => 'Wind', 'name_ru' => 'Кондиционеры', 'sort_order' => 150],

            ['id' => 'beauty-health', 'slug' => 'beauty-health', 'icon' => 'Heart', 'name_ru' => 'Красота и здоровье', 'sort_order' => 200],
            ['id' => 'manicure', 'slug' => 'manicure', 'parent_id' => 'beauty-health', 'icon' => 'Hand', 'name_ru' => 'Маникюр', 'sort_order' => 210],
            ['id' => 'hairdresser', 'slug' => 'hairdresser', 'parent_id' => 'beauty-health', 'icon' => 'Scissors', 'name_ru' => 'Парикмахер', 'sort_order' => 220],
            ['id' => 'massage', 'slug' => 'massage', 'parent_id' => 'beauty-health', 'icon' => 'HeartPulse', 'name_ru' => 'Массаж', 'sort_order' => 230],

            ['id' => 'education', 'slug' => 'education', 'icon' => 'GraduationCap', 'name_ru' => 'Обучение', 'sort_order' => 300],
            ['id' => 'tutoring', 'slug' => 'tutoring', 'parent_id' => 'education', 'icon' => 'BookOpen', 'name_ru' => 'Репетиторы', 'sort_order' => 310],
            ['id' => 'english-tutor', 'slug' => 'english-tutor', 'parent_id' => 'education', 'icon' => 'Languages', 'name_ru' => 'Английский язык', 'sort_order' => 320],

            ['id' => 'auto', 'slug' => 'auto', 'icon' => 'Car', 'name_ru' => 'Авто', 'sort_order' => 400],
            ['id' => 'auto-repair', 'slug' => 'auto-repair', 'parent_id' => 'auto', 'icon' => 'Wrench', 'name_ru' => 'Ремонт авто', 'sort_order' => 410],
            ['id' => 'driver-courier', 'slug' => 'driver-courier', 'parent_id' => 'auto', 'icon' => 'Navigation', 'name_ru' => 'Водители и курьеры', 'sort_order' => 420],

            ['id' => 'digital', 'slug' => 'digital', 'icon' => 'Laptop', 'name_ru' => 'IT и digital', 'sort_order' => 500],
            ['id' => 'computer-help', 'slug' => 'computer-help', 'parent_id' => 'digital', 'icon' => 'MonitorCog', 'name_ru' => 'Компьютерная помощь', 'sort_order' => 510],
            ['id' => 'design', 'slug' => 'design', 'parent_id' => 'digital', 'icon' => 'Palette', 'name_ru' => 'Дизайн', 'sort_order' => 520],

            ['id' => 'other', 'slug' => 'other', 'icon' => 'MoreHorizontal', 'name_ru' => 'Другое', 'sort_order' => 900],
        ];
    }
}
