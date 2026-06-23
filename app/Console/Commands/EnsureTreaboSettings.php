<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marvel OrderController requires Settings::first() at boot — empty table breaks route:list.
 */
class EnsureTreaboSettings extends Command
{
    protected $signature = 'treabo:ensure-settings
                            {--language= : Language code (defaults to shop.default_language or ru)}';

    protected $description = 'Create a default Marvel settings row if the table is empty (safe, no overwrite)';

    public function handle(): int
    {
        if (! Schema::hasTable('settings')) {
            $this->error('Table "settings" does not exist. Run migrations first: php artisan migrate');

            return self::FAILURE;
        }

        $count = DB::table('settings')->count();
        if ($count > 0) {
            $this->info("Settings already exist ({$count} row(s)). Nothing changed.");

            return self::SUCCESS;
        }

        $language = $this->option('language')
            ?: config('shop.default_language', env('DEFAULT_LANGUAGE', 'ru'));

        $shopUrl = rtrim((string) config('shop.shop_url', env('SHOP_URL', 'https://treabo.md')), '/');
        $currency = config('shop.default_currency', env('DEFAULT_CURRENCY', 'MDL'));

        $row = [
            'options' => json_encode($this->defaultOptions($shopUrl, $currency), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'language' => $language,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        if (Schema::hasColumn('settings', 'active_payment_gateway')) {
            $row['active_payment_gateway'] = env('ACTIVE_PAYMENT_GATEWAY', 'yookassa');
        }

        if (Schema::hasColumn('settings', 'yookassa_settings')) {
            $row['yookassa_settings'] = null;
        }

        DB::table('settings')->insert($row);

        $this->info("Created default settings row (language: {$language}).");
        $this->line('You can edit values later in admin or via PUT /settings.');

        return self::SUCCESS;
    }

    /**
     * Minimal options so Marvel controllers boot; tune in admin after deploy.
     */
    private function defaultOptions(string $shopUrl, string $currency): array
    {
        $options = [
            'seo' => [
                'ogImage' => null,
                'ogTitle' => null,
                'metaTags' => null,
                'metaTitle' => null,
                'canonicalUrl' => null,
                'ogDescription' => null,
                'twitterHandle' => null,
                'metaDescription' => null,
                'twitterCardType' => null,
            ],
            'siteTitle' => 'Treabo',
            'siteSubtitle' => 'Treabo marketplace',
            'currency' => $currency,
            'taxClass' => '1',
            'signupPoints' => 0,
            'useGoogleMap' => false,
            'paymentGateway' => [
                [
                    'name' => 'yookassa',
                    'title' => 'YooKassa',
                ],
                'yookassa' => [
                    'success_url' => $shopUrl . '/orders',
                    'cancel_url' => $shopUrl . '/checkout',
                ],
            ],
            'currencyOptions' => [
                'formation' => 'ru-RU',
                'fractions' => 2,
            ],
            'isProductReview' => false,
            'useEnableGateway' => true,
            'minimumOrderAmount' => null,
            'useMustVerifyEmail' => false,
            'maximumQuestionLimit' => 5,
            'currencyToWalletRatio' => 1,
            'defaultPaymentGateway' => 'yookassa',
            'useAi' => false,
            'defaultAi' => 'openai',
            'smsEvent' => [
                'admin' => ['statusChangeOrder' => false, 'refundOrder' => false, 'paymentOrder' => false],
                'vendor' => ['statusChangeOrder' => false, 'paymentOrder' => false, 'refundOrder' => false],
                'customer' => ['statusChangeOrder' => false, 'refundOrder' => false, 'paymentOrder' => false],
            ],
            'emailEvent' => [
                'admin' => ['statusChangeOrder' => false, 'refundOrder' => false, 'paymentOrder' => false],
                'vendor' => [
                    'createQuestion' => false,
                    'statusChangeOrder' => false,
                    'refundOrder' => false,
                    'paymentOrder' => false,
                    'createReview' => false,
                ],
                'customer' => [
                    'statusChangeOrder' => false,
                    'refundOrder' => false,
                    'paymentOrder' => false,
                    'answerQuestion' => false,
                ],
            ],
        ];

        if (function_exists('server_environment_info')) {
            $options['server_info'] = server_environment_info();
        }

        return $options;
    }
}
