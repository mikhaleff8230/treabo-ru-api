<?php

namespace Database\Seeders;

use App\Models\ProffiApplication;
use App\Models\ProffiCategory;
use App\Models\ProffiChat;
use App\Models\ProffiFilter;
use App\Models\ProffiMessage;
use App\Models\ProffiTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as UserPermission;
use Spatie\Permission\Models\Permission;

class TreaboBaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensurePermissions();
        $this->seedDictionaries();

        $customers = [
            $this->user(
                email: 'customer.one@treabo-demo.local',
                name: 'Андрей Попеску',
                phone: '+37360111001',
                role: UserPermission::CUSTOMER,
                city: 'Chișinău'
            ),
            $this->user(
                email: 'customer.two@treabo-demo.local',
                name: 'Мария Русу',
                phone: '+37360111002',
                role: UserPermission::CUSTOMER,
                city: 'Bălți'
            ),
        ];

        $specialists = [
            $this->user(
                email: 'master.one@treabo-demo.local',
                name: 'Ион Чебан',
                phone: '+37369111001',
                role: UserPermission::STORE_OWNER,
                city: 'Chișinău',
                services: ['Ремонт санузлов', 'Плиточные работы']
            ),
            $this->user(
                email: 'master.two@treabo-demo.local',
                name: 'Виктор Мунтяну',
                phone: '+37369111002',
                role: UserPermission::STORE_OWNER,
                city: 'Bălți',
                services: ['Электрика', 'Сантехника']
            ),
        ];

        $tasks = [
            $this->task([
                'title' => 'Ремонт санузла',
                'description' => 'Нужно обновить ванную комнату: плитка на стены и пол, подключение сантехники, аккуратная отделка.',
                'category' => 'bathroom-renovation',
                'city' => 'Chișinău',
                'address' => 'Chișinău, Botanica, str. Teilor 12',
                'budget' => 150000,
                'response_price_mdl' => 15,
                'deadline' => 'На этой неделе',
                'status' => 'open',
                'customer_id' => $customers[0]->id,
                'lat' => 47.0105,
                'lng' => 28.8638,
                'photos' => [
                    'https://images.unsplash.com/photo-1584622650111-993a426fbf0a?q=80&w=1200&auto=format&fit=crop',
                ],
            ]),
            $this->task([
                'title' => 'Покраска стен в квартире',
                'description' => 'Площадь стен около 45 м². Нужно подготовить поверхность и покрасить две комнаты.',
                'category' => 'other',
                'city' => 'Chișinău',
                'address' => 'Chișinău, Centru, bd. Ștefan cel Mare 87',
                'budget' => 12000,
                'response_price_mdl' => 15,
                'deadline' => 'До конца месяца',
                'status' => 'open',
                'customer_id' => $customers[0]->id,
                'lat' => 47.0245,
                'lng' => 28.8323,
                'photos' => [],
            ]),
            $this->task([
                'title' => 'Проверить электрику в доме',
                'description' => 'Периодически выбивает автомат. Нужен осмотр щитка и поиск причины.',
                'category' => 'electrical',
                'city' => 'Bălți',
                'address' => 'Bălți, str. Independenței 24',
                'budget' => 2500,
                'response_price_mdl' => 15,
                'deadline' => 'Срочно',
                'status' => 'open',
                'customer_id' => $customers[1]->id,
                'lat' => 47.7539,
                'lng' => 27.9184,
                'photos' => [],
            ]),
        ];

        $this->applicationWithChat(
            task: $tasks[0],
            specialist: $specialists[0],
            price: 145000,
            status: 'pending',
            message: 'Здравствуйте. Могу приехать на осмотр сегодня вечером, после этого дам точную смету.',
            messages: [
                [$specialists[0], 'Здравствуйте. Готов посмотреть санузел и рассчитать материалы.'],
                [$customers[0], 'Добрый день, удобно после 18:00. Фото добавила в заявку.'],
            ]
        );

        $this->applicationWithChat(
            task: $tasks[1],
            specialist: $specialists[0],
            price: 11500,
            status: 'pending',
            message: 'Могу выполнить за 2 дня, краску можно купить вместе после осмотра.',
            messages: [
                [$specialists[0], 'Здравствуйте, стены нужно шпаклевать или только покраска?'],
                [$customers[0], 'Есть мелкие трещины, нужно посмотреть на месте.'],
            ]
        );

        $this->applicationWithChat(
            task: $tasks[2],
            specialist: $specialists[1],
            price: 2200,
            status: 'accepted',
            message: 'Могу приехать сегодня и проверить щиток.',
            messages: [
                [$specialists[1], 'Здравствуйте, могу быть через час.'],
                [$customers[1], 'Отлично, жду. Адрес актуальный.'],
            ]
        );
    }

    private function ensurePermissions(): void
    {
        foreach ([UserPermission::CUSTOMER, UserPermission::STORE_OWNER, UserPermission::SUPER_ADMIN] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    private function seedDictionaries(): void
    {
        foreach ([
            ['id' => 'bathroom-renovation', 'icon' => 'Bath', 'name_ru' => 'Ремонт ванной', 'name_ro' => 'Reparație baie'],
            ['id' => 'tile-work', 'icon' => 'Grid3X3', 'name_ru' => 'Плиточные работы', 'name_ro' => 'Lucrări cu faianță'],
            ['id' => 'plumbing', 'icon' => 'Wrench', 'name_ru' => 'Сантехника', 'name_ro' => 'Instalații sanitare'],
            ['id' => 'electrical', 'icon' => 'Zap', 'name_ru' => 'Электрика', 'name_ro' => 'Electricitate'],
            ['id' => 'air-conditioners', 'icon' => 'Wind', 'name_ru' => 'Кондиционеры', 'name_ro' => 'Aparate de aer condiționat'],
            ['id' => 'other', 'icon' => 'MoreHorizontal', 'name_ru' => 'Другое', 'name_ro' => 'Altele'],
        ] as $category) {
            ProffiCategory::updateOrCreate(['id' => $category['id']], $category);
        }

        foreach ([
            ['id' => 'with-photo', 'name' => 'Фото объекта', 'key' => 'has_photo', 'value' => '1'],
            ['id' => 'urgent', 'name' => 'Срочно', 'key' => 'deadline', 'value' => 'urgent'],
            ['id' => 'materials-ready', 'name' => 'Материалы есть', 'key' => 'materials', 'value' => 'ready'],
        ] as $filter) {
            ProffiFilter::updateOrCreate(['id' => $filter['id']], $filter);
        }
    }

    private function user(
        string $email,
        string $name,
        string $phone,
        string $role,
        string $city,
        array $services = []
    ): User {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('TreaboDemo123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $user->givePermissionTo($role);
        if ($role === UserPermission::STORE_OWNER) {
            $user->givePermissionTo(UserPermission::CUSTOMER);
        }

        Profile::updateOrCreate(
            ['customer_id' => $user->id],
            [
                'contact' => $phone,
                'proffi_city' => $city,
                'proffi_services' => $services,
                'phone_verified' => true,
                'phone_verified_at' => now(),
            ]
        );

        return $user->fresh('profile');
    }

    private function task(array $data): ProffiTask
    {
        return ProffiTask::updateOrCreate(
            [
                'title' => $data['title'],
                'customer_id' => $data['customer_id'],
            ],
            $data
        );
    }

    private function applicationWithChat(
        ProffiTask $task,
        User $specialist,
        int $price,
        string $status,
        string $message,
        array $messages
    ): void {
        $application = ProffiApplication::updateOrCreate(
            [
                'task_id' => $task->id,
                'specialist_id' => $specialist->id,
            ],
            [
                'message' => $message,
                'price' => $price,
                'response_fee_mdl' => $task->response_price_mdl ?: 15,
                'status' => $status,
            ]
        );

        $chat = ProffiChat::updateOrCreate(
            [
                'task_id' => $task->id,
                'specialist_id' => $specialist->id,
            ],
            [
                'application_id' => $application->id,
                'customer_id' => $task->customer_id,
                'last_message' => end($messages)[1],
                'last_message_at' => now(),
            ]
        );

        ProffiMessage::where('chat_id', $chat->id)
            ->whereIn('text', collect($messages)->pluck(1)->all())
            ->delete();

        foreach ($messages as [$sender, $text]) {
            ProffiMessage::create([
                'chat_id' => $chat->id,
                'sender_id' => $sender->id,
                'text' => $text,
                'created_at' => now()->subMinutes(random_int(2, 40)),
                'updated_at' => now(),
            ]);
        }
    }
}
