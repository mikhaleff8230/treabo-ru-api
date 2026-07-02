<?php

namespace Database\Seeders;

use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use Illuminate\Database\Seeder;

class ProffiWorksAndQuestionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCategories();
        $this->seedWorksAndQuestions();
    }

    private function seedCategories(): void
    {
        $categories = [
            ['id' => 'bathroom-renovation', 'icon' => 'Bath', 'name_ru' => 'Ремонт ванной', 'name_ro' => 'Ремонт ванной', 'slug' => 'bathroom-renovation', 'sort_order' => 10],
            ['id' => 'tile-work', 'icon' => 'Grid3X3', 'name_ru' => 'Плиточные работы', 'name_ro' => 'Плиточные работы', 'slug' => 'tile-work', 'sort_order' => 20],
            ['id' => 'plumbing', 'icon' => 'Wrench', 'name_ru' => 'Сантехника', 'name_ro' => 'Сантехника', 'slug' => 'plumbing', 'sort_order' => 30],
            ['id' => 'electrical', 'icon' => 'Zap', 'name_ru' => 'Электрика', 'name_ro' => 'Электрика', 'slug' => 'electrical', 'sort_order' => 40],
            ['id' => 'air-conditioners', 'icon' => 'Wind', 'name_ru' => 'Кондиционеры', 'name_ro' => 'Кондиционеры', 'slug' => 'air-conditioners', 'sort_order' => 50],
            ['id' => 'other', 'icon' => 'MoreHorizontal', 'name_ru' => 'Другое', 'name_ro' => 'Другое', 'slug' => 'other', 'sort_order' => 99],
        ];

        foreach ($categories as $category) {
            ProffiCategory::updateOrCreate(
                ['id' => $category['id']],
                [
                    'icon' => $category['icon'],
                    'name_ru' => $category['name_ru'],
                    'name_ro' => $category['name_ro'],
                    'slug' => $category['slug'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                ]
            );
        }
    }

    private function seedWorksAndQuestions(): void
    {
        $catalog = [
            'bathroom-renovation' => [
                [
                    'title' => 'Ремонт ванной под ключ',
                    'slug' => 'bathroom-turnkey',
                    'aliases' => ['ремонт ванной', 'ванная комната', 'санузел', 'ремонт санузла'],
                    'description' => 'Полный ремонт ванной: демонтаж, подготовка, плитка, сантехника, электрика.',
                    'sort_order' => 10,
                    'questions' => [
                        ['field_key' => 'area', 'question' => 'Какая площадь ванной комнаты?', 'type' => 'number', 'placeholder' => 'Например: 5 м²', 'help_text' => 'Укажите площадь пола или всей комнаты', 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'demolition', 'question' => 'Нужен ли демонтаж старой отделки?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'materials_ready', 'question' => 'Материалы уже куплены?', 'type' => 'yesno', 'help_text' => 'Плитка, сантехника, смесители и т.д.', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Есть ли фото текущего состояния?', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
                [
                    'title' => 'Укладка плитки в ванной',
                    'slug' => 'bathroom-tiling',
                    'aliases' => ['плитка в ванной', 'кафель ванная', 'облицовка ванной'],
                    'description' => 'Укладка плитки на пол и стены в ванной комнате.',
                    'sort_order' => 20,
                    'questions' => [
                        ['field_key' => 'area', 'question' => 'Какая площадь укладки плитки?', 'type' => 'number', 'placeholder' => 'м²', 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'surface', 'question' => 'Где нужна плитка?', 'type' => 'select', 'options' => ['Пол', 'Стены', 'Пол и стены'], 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'old_tile_removal', 'question' => 'Нужно снять старую плитку?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'tile_bought', 'question' => 'Плитка уже куплена?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 40],
                    ],
                ],
            ],
            'tile-work' => [
                [
                    'title' => 'Укладка плитки',
                    'slug' => 'tile-installation',
                    'aliases' => ['укладка плитки', 'кафель', 'плиточник', 'фартук на кухне'],
                    'description' => 'Укладка керамической плитки на пол, стены, фартук.',
                    'sort_order' => 10,
                    'questions' => [
                        ['field_key' => 'area', 'question' => 'Какая площадь работ?', 'type' => 'number', 'placeholder' => 'м²', 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'room_type', 'question' => 'Где выполняются работы?', 'type' => 'select', 'options' => ['Ванная', 'Кухня', 'Коридор', 'Другое'], 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'old_tile_removal', 'question' => 'Нужен демонтаж старой плитки?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Приложите фото объекта', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
                [
                    'title' => 'Ремонт и замена плитки',
                    'slug' => 'tile-repair',
                    'aliases' => ['замена плитки', 'починить плитку', 'откололась плитка'],
                    'description' => 'Локальный ремонт, замена отдельных плиток, восстановление швов.',
                    'sort_order' => 20,
                    'questions' => [
                        ['field_key' => 'damage_area', 'question' => 'Сколько плиток нужно заменить или починить?', 'type' => 'number', 'placeholder' => 'шт.', 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'has_spare_tiles', 'question' => 'Есть запасная плитка?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'problem_description', 'question' => 'Опишите проблему', 'type' => 'textarea', 'placeholder' => 'Откололась плитка, треснула, отошёл шов...', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото повреждённого участка', 'type' => 'photo', 'is_required' => true, 'sort_order' => 40],
                    ],
                ],
            ],
            'plumbing' => [
                [
                    'title' => 'Установка и замена сантехники',
                    'slug' => 'plumbing-install',
                    'aliases' => ['установка унитаза', 'замена смесителя', 'монтаж ванны', 'сантехник'],
                    'description' => 'Установка, замена и подключение сантехнического оборудования.',
                    'sort_order' => 10,
                    'questions' => [
                        ['field_key' => 'fixture_type', 'question' => 'Что нужно установить или заменить?', 'type' => 'select', 'options' => ['Смеситель', 'Унитаз', 'Ванна', 'Душевая кабина', 'Раковина', 'Бойлер', 'Другое'], 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'equipment_bought', 'question' => 'Оборудование уже куплено?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'urgent', 'question' => 'Нужен срочный выезд?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото места работ', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
                [
                    'title' => 'Прочистка и ремонт труб',
                    'slug' => 'plumbing-pipes',
                    'aliases' => ['засор', 'протечка', 'трубы', 'канализация', 'авария сантехника'],
                    'description' => 'Устранение засоров, протечек, ремонт и замена труб.',
                    'sort_order' => 20,
                    'questions' => [
                        ['field_key' => 'problem_type', 'question' => 'Какая проблема?', 'type' => 'select', 'options' => ['Засор', 'Протечка', 'Слабый напор воды', 'Замена труб', 'Другое'], 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'location', 'question' => 'Где проблема?', 'type' => 'select', 'options' => ['Кухня', 'Ванная', 'Туалет', 'Вся квартира', 'Другое'], 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'urgent', 'question' => 'Это аварийная ситуация?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото места протечки или засора', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
            ],
            'electrical' => [
                [
                    'title' => 'Монтаж розеток и выключателей',
                    'slug' => 'electrical-outlets',
                    'aliases' => ['розетки', 'выключатели', 'электрик', 'перенос розетки'],
                    'description' => 'Установка, замена и перенос розеток, выключателей, диммеров.',
                    'sort_order' => 10,
                    'questions' => [
                        ['field_key' => 'points_count', 'question' => 'Сколько точек нужно установить или заменить?', 'type' => 'number', 'placeholder' => 'шт.', 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'work_type', 'question' => 'Тип работ', 'type' => 'select', 'options' => ['Новый монтаж', 'Замена существующих', 'Перенос точек'], 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'materials_ready', 'question' => 'Розетки и выключатели уже куплены?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото места работ', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
                [
                    'title' => 'Ремонт электрощитка и проводки',
                    'slug' => 'electrical-wiring',
                    'aliases' => ['щиток', 'автомат выбивает', 'проводка', 'электропроводка', 'диагностика электрики'],
                    'description' => 'Диагностика, ремонт щитка, замена автоматов, частичная или полная замена проводки.',
                    'sort_order' => 20,
                    'questions' => [
                        ['field_key' => 'problem', 'question' => 'Что произошло?', 'type' => 'select', 'options' => ['Выбивает автомат', 'Искрит/греется', 'Нет света в части квартиры', 'Нужна замена проводки', 'Плановый осмотр'], 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'property_type', 'question' => 'Тип помещения', 'type' => 'select', 'options' => ['Квартира', 'Частный дом', 'Офис', 'Коммерческое'], 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'urgent', 'question' => 'Нужен срочный выезд?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото щитка или места проблемы', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
            ],
            'air-conditioners' => [
                [
                    'title' => 'Установка кондиционера',
                    'slug' => 'ac-installation',
                    'aliases' => ['кондиционер', 'сплит система', 'монтаж кондиционера', 'установка сплита'],
                    'description' => 'Монтаж настенного или другого типа кондиционера с прокладкой трассы.',
                    'sort_order' => 10,
                    'questions' => [
                        ['field_key' => 'ac_type', 'question' => 'Тип кондиционера', 'type' => 'select', 'options' => ['Настенный (сплит)', 'Кассетный', 'Напольный', 'Не знаю — нужна консультация'], 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'blocks_count', 'question' => 'Сколько блоков нужно установить?', 'type' => 'number', 'placeholder' => 'шт.', 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'floor', 'question' => 'На каком этаже установка?', 'type' => 'number', 'placeholder' => 'этаж', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'ac_bought', 'question' => 'Кондиционер уже куплен?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 40],
                    ],
                ],
                [
                    'title' => 'Обслуживание и ремонт кондиционера',
                    'slug' => 'ac-service',
                    'aliases' => ['чистка кондиционера', 'заправка фреоном', 'кондиционер не холодит', 'ремонт сплита'],
                    'description' => 'Чистка, дезинфекция, заправка, диагностика и ремонт кондиционеров.',
                    'sort_order' => 20,
                    'questions' => [
                        ['field_key' => 'service_type', 'question' => 'Какая услуга нужна?', 'type' => 'select', 'options' => ['Чистка/обслуживание', 'Заправка фреоном', 'Не холодит', 'Не включается', 'Шумит/течёт', 'Другое'], 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'blocks_count', 'question' => 'Сколько блоков?', 'type' => 'number', 'placeholder' => 'шт.', 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'last_service', 'question' => 'Когда последний раз обслуживали?', 'type' => 'select', 'options' => ['Никогда', 'Больше года назад', 'В этом году', 'Не помню'], 'is_required' => false, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото внутреннего или наружного блока', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
            ],
            'other' => [
                [
                    'title' => 'Покраска стен и потолков',
                    'slug' => 'painting',
                    'aliases' => ['покраска', 'покрасить стены', 'маляр', 'обои'],
                    'description' => 'Подготовка поверхностей и покраска стен, потолков.',
                    'sort_order' => 10,
                    'questions' => [
                        ['field_key' => 'area', 'question' => 'Какая площадь покраски?', 'type' => 'number', 'placeholder' => 'м²', 'is_required' => true, 'sort_order' => 10],
                        ['field_key' => 'surface_prep', 'question' => 'Нужна ли подготовка стен (шпаклёвка, грунтовка)?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 20],
                        ['field_key' => 'paint_bought', 'question' => 'Краска уже куплена?', 'type' => 'yesno', 'is_required' => true, 'sort_order' => 30],
                        ['field_key' => 'photos', 'question' => 'Фото помещения', 'type' => 'photo', 'is_required' => false, 'sort_order' => 40],
                    ],
                ],
            ],
        ];

        foreach ($catalog as $categoryId => $works) {
            foreach ($works as $workData) {
                $questions = $workData['questions'];
                unset($workData['questions']);

                $work = ProffiWork::updateOrCreate(
                    ['slug' => $workData['slug']],
                    [
                        'category_id' => $categoryId,
                        'title' => $workData['title'],
                        'aliases' => $workData['aliases'] ?? null,
                        'description' => $workData['description'] ?? null,
                        'sort_order' => $workData['sort_order'] ?? 0,
                        'is_active' => true,
                    ]
                );

                foreach ($questions as $questionData) {
                    ProffiWorkQuestion::updateOrCreate(
                        [
                            'work_id' => $work->id,
                            'field_key' => $questionData['field_key'],
                        ],
                        [
                            'question' => $questionData['question'],
                            'type' => $questionData['type'],
                            'options' => $questionData['options'] ?? null,
                            'placeholder' => $questionData['placeholder'] ?? null,
                            'help_text' => $questionData['help_text'] ?? null,
                            'is_required' => $questionData['is_required'] ?? false,
                            'sort_order' => $questionData['sort_order'] ?? 0,
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }
}
