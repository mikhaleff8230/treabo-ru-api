<?php

namespace Database\Seeders;

use App\Models\ProffiCategory;
use App\Models\ProffiTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as UserPermission;
use Spatie\Permission\Models\Permission;

class TreaboLocalDevSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensurePermissions();
        $this->seedCategories();

        $customer = $this->user(
            email: 'local.customer@treabo.local',
            name: 'Андрей',
            phone: '+79990000001',
            role: UserPermission::CUSTOMER,
        );

        $this->user(
            email: 'local.master@treabo.local',
            name: 'Мастер Сергей',
            phone: '+79990000002',
            role: UserPermission::STORE_OWNER,
            services: ['Ремонт', 'Покраска', 'Электрика'],
        );

        foreach ($this->tasks($customer->id) as $task) {
            ProffiTask::updateOrCreate(
                [
                    'title' => $task['title'],
                    'customer_id' => $customer->id,
                ],
                $task,
            );
        }
    }

    private function ensurePermissions(): void
    {
        foreach ([UserPermission::CUSTOMER, UserPermission::STORE_OWNER, UserPermission::SUPER_ADMIN] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    private function seedCategories(): void
    {
        foreach ([
            ['id' => 'painting', 'icon' => 'Paintbrush', 'name_ru' => 'Покраска', 'name_ro' => 'Vopsire'],
            ['id' => 'bathroom-renovation', 'icon' => 'Bath', 'name_ru' => 'Ремонт ванной', 'name_ro' => 'Renovare baie'],
            ['id' => 'electrical', 'icon' => 'Zap', 'name_ru' => 'Электрика', 'name_ro' => 'Electricitate'],
            ['id' => 'air-conditioners', 'icon' => 'Wind', 'name_ru' => 'Кондиционеры', 'name_ro' => 'Aer conditionat'],
            ['id' => 'other', 'icon' => 'MoreHorizontal', 'name_ru' => 'Другое', 'name_ro' => 'Altele'],
        ] as $category) {
            ProffiCategory::updateOrCreate(['id' => $category['id']], $category);
        }
    }

    private function user(
        string $email,
        string $name,
        string $phone,
        string $role,
        array $services = [],
    ): User {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('local-dev'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->givePermissionTo($role);
        if ($role === UserPermission::STORE_OWNER) {
            $user->givePermissionTo(UserPermission::CUSTOMER);
        }

        Profile::updateOrCreate(
            ['customer_id' => $user->id],
            [
                'contact' => $phone,
                'proffi_city' => 'Москва',
                'proffi_services' => $services,
                'phone_verified' => true,
                'phone_verified_at' => now(),
            ],
        );

        return $user->fresh('profile');
    }

    private function tasks(int $customerId): array
    {
        return [
            [
                'title' => 'Покрасить комнату и потолок',
                'description' => 'Нужно подготовить стены, покрасить комнату и потолок. Материалы можно обсудить.',
                'category' => 'painting',
                'city' => 'Москва',
                'address' => 'Москва, Тверская улица, 10',
                'budget' => 25000,
                'response_price_mdl' => 0,
                'deadline' => 'На этой неделе',
                'status' => 'open',
                'customer_id' => $customerId,
                'lat' => 55.7601,
                'lng' => 37.6186,
                'photos' => [
                    '/proffi/task-preview-paint.svg',
                    '/proffi/task-preview-repair.svg',
                    'https://images.unsplash.com/photo-1562259949-e8e7689d7828?auto=format&fit=crop&w=800&q=80',
                ],
            ],
            [
                'title' => 'Ремонт ванной под ключ',
                'description' => 'Нужен мастер на ремонт ванной: демонтаж, плитка, сантехника, чистовая отделка.',
                'category' => 'bathroom-renovation',
                'city' => 'Москва',
                'address' => 'Москва, Ленинградский проспект, 31',
                'budget' => 160000,
                'response_price_mdl' => 0,
                'deadline' => 'В течение месяца',
                'status' => 'open',
                'customer_id' => $customerId,
                'lat' => 55.7829,
                'lng' => 37.5737,
                'photos' => [],
            ],
            [
                'title' => 'Проверить электрику в квартире',
                'description' => 'Периодически выбивает автомат. Нужно проверить щиток и розетки.',
                'category' => 'electrical',
                'city' => 'Москва',
                'address' => 'Москва, Большая Садовая улица, 5',
                'budget' => 5000,
                'response_price_mdl' => 0,
                'deadline' => 'Срочно',
                'status' => 'open',
                'customer_id' => $customerId,
                'lat' => 55.7677,
                'lng' => 37.5946,
                'photos' => [],
            ],
            [
                'title' => 'Установить кондиционер',
                'description' => 'Нужно установить сплит-систему, трасса короткая, доступ удобный.',
                'category' => 'air-conditioners',
                'city' => 'Москва',
                'address' => 'Москва, Арбат, 24',
                'budget' => 14000,
                'response_price_mdl' => 0,
                'deadline' => 'На выходных',
                'status' => 'open',
                'customer_id' => $customerId,
                'lat' => 55.7497,
                'lng' => 37.5899,
                'photos' => [],
            ],
            [
                'title' => 'Собрать кухонные шкафы',
                'description' => 'Нужно собрать и повесить 4 кухонных шкафа, крепеж есть.',
                'category' => 'other',
                'city' => 'Москва',
                'address' => 'Москва, проспект Мира, 79',
                'budget' => 8000,
                'response_price_mdl' => 0,
                'deadline' => 'Любой день',
                'status' => 'open',
                'customer_id' => $customerId,
                'lat' => 55.7889,
                'lng' => 37.6334,
                'photos' => [],
            ],
        ];
    }
}
