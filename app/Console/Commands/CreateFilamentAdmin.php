<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateFilamentAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'filament:create-admin 
                            {--name= : Имя администратора}
                            {--email= : Email администратора}
                            {--password= : Пароль администратора}
                            {--use-existing : Использовать существующего пользователя (обновить пароль и назначить роль)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создать администратора для Filament панели или обновить существующего пользователя';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->option('name') ?: $this->ask('Введите имя администратора');
        $email = $this->option('email') ?: $this->ask('Введите email администратора');
        $password = $this->option('password') ?: $this->secret('Введите пароль администратора');

        $useExisting = $this->option('use-existing');
        $existingUser = User::where('email', $email)->first();

        // Если пользователь существует и не указан флаг --use-existing
        if ($existingUser && !$useExisting) {
            $this->warn("Пользователь с email {$email} уже существует!");
            if ($this->confirm('Хотите обновить этого пользователя (пароль и роль)?', true)) {
                $useExisting = true;
            } else {
                $this->info('Используйте другой email или добавьте флаг --use-existing для обновления существующего пользователя.');
                return 1;
            }
        }

        // Валидация
        $rules = [
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ];

        if (!$useExisting && !$existingUser) {
            $rules['email'] = 'required|email|unique:users,email';
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], $rules);

        if ($validator->fails()) {
            $this->error('Ошибки валидации:');
            foreach ($validator->errors()->all() as $error) {
                $this->error('  - ' . $error);
            }
            return 1;
        }

        // Создаем или обновляем пользователя
        try {
            if ($useExisting && $existingUser) {
                // Обновляем существующего пользователя
                $existingUser->update([
                    'name' => $name,
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'email_verified_at' => $existingUser->email_verified_at ?? now(),
                ]);
                $user = $existingUser;
                $this->info("✅ Существующий пользователь обновлен!");
            } else {
                // Создаем нового пользователя
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'email_verified_at' => now(), // Автоматически верифицируем email
                ]);
                $this->info("✅ Новый администратор создан!");
            }

            // Назначаем роль super_admin через Spatie Permission
            // Важно: Filament использует guard 'web', поэтому нужно назначить роль для guard 'web'
            if (method_exists($user, 'assignRole')) {
                try {
                    // Сначала создаем роль для guard 'web' (если не существует)
                    $role = \Spatie\Permission\Models\Role::firstOrCreate(
                        ['name' => 'super_admin', 'guard_name' => 'web']
                    );
                    $this->info('Роль super_admin для guard "web" создана/найдена.');
                    
                    // Назначаем роль пользователю для guard 'web'
                    // Используем setGuardName чтобы временно изменить guard для назначения роли
                    $originalGuard = $user->guard_name ?? 'api';
                    
                    // Назначаем роль через прямое обращение к модели Role
                    if (!$user->hasRole($role)) {
                        $user->roles()->syncWithoutDetaching([$role->id]);
                        $this->info('Роль super_admin для guard "web" назначена пользователю.');
                    } else {
                        $this->info('Роль super_admin для guard "web" уже назначена пользователю.');
                    }
                    
                    // Также убеждаемся, что роль назначена для guard 'api' (для совместимости)
                    try {
                        $roleApi = \Spatie\Permission\Models\Role::firstOrCreate(
                            ['name' => 'super_admin', 'guard_name' => 'api']
                        );
                        if (!$user->hasRole($roleApi)) {
                            $user->roles()->syncWithoutDetaching([$roleApi->id]);
                            $this->info('Роль super_admin для guard "api" также назначена пользователю.');
                        }
                    } catch (\Exception $e) {
                        $this->warn('Не удалось назначить роль для guard "api": ' . $e->getMessage());
                    }
                    
                } catch (\Exception $e) {
                    $this->warn('Не удалось назначить роль super_admin: ' . $e->getMessage());
                    $this->warn('Убедитесь, что роль super_admin существует в базе данных.');
                    $this->info('Попробуйте создать роль вручную:');
                    $this->info('  php artisan tinker');
                    $this->info('  >>> \\Spatie\\Permission\\Models\\Role::create([\'name\' => \'super_admin\', \'guard_name\' => \'web\']);');
                }
            }

            // Next.js seller admin authorizes by direct permissions from /token.
            // Roles alone are not enough for the React admin guard.
            $adminPermissions = [
                Permission::SUPER_ADMIN,
                Permission::STORE_OWNER,
                Permission::CUSTOMER,
            ];

            foreach ($adminPermissions as $permissionName) {
                \Spatie\Permission\Models\Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'api',
                ]);
            }

            $user->givePermissionTo($adminPermissions);
            $user->forgetCachedPermissions();
            $this->info('Права super_admin/store_owner/customer для Next.js admin назначены.');

            $this->info("✅ Администратор успешно создан!");
            $this->info("   Имя: {$user->name}");
            $this->info("   Email: {$user->email}");
            $this->info("   ID: {$user->id}");
            $this->newLine();
            $this->info("Теперь вы можете войти в Filament панель:");
            $this->info("   URL: " . rtrim(config('app.url'), '/') . '/admin/login');
            $this->info("   Email: {$user->email}");
            $this->info("   Пароль: (введенный вами пароль)");

            return 0;
        } catch (\Exception $e) {
            $this->error("Ошибка при создании администратора: " . $e->getMessage());
            $this->error("Трассировка: " . $e->getTraceAsString());
            return 1;
        }
    }
}

