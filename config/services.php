<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sberbank' => [
        'username' => env('SBERBANK_USERNAME'),
        'password' => env('SBERBANK_PASSWORD'),
        'test_mode' => env('SBERBANK_TEST_MODE', true),
        'api_url' => env('SBERBANK_API_URL', 'https://3dsec.sberbank.ru'),
        'success_url' => env('SBERBANK_SUCCESS_URL'),
        'fail_url' => env('SBERBANK_FAIL_URL'),
    ],

    'tinkoff' => [
        'terminal' => env('TINKOFF_TERMINAL_KEY'),
        'password' => env('TINKOFF_PASSWORD'),
        'is_test' => env('TINKOFF_TEST_MODE', true),
        'api_url' => env('TINKOFF_API_URL', 'https://securepay.tinkoff.ru/v2'),
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
        'is_test' => env('YOOKASSA_TEST_MODE', true),
    ],

    'treabo_balance' => [
        'manual_payment_url' => env('TREABO_BALANCE_MANUAL_PAYMENT_URL'),
        'manual_deposit_amount_mdl' => env('TREABO_BALANCE_MANUAL_DEPOSIT_AMOUNT_MDL', 100),
        'manual_payment_expires_hours' => env('TREABO_BALANCE_MANUAL_PAYMENT_EXPIRES_HOURS', 24),
    ],

    'treabo_responses' => [
        'free_daily_limit' => env('TREABO_FREE_DAILY_RESPONSES', 5),
        'default_price_mdl' => env('TREABO_DEFAULT_RESPONSE_PRICE_MDL', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Маркетплейс: физическая доставка и логистика
    |--------------------------------------------------------------------------
    | По умолчанию выключено (цифровой маркетплейс). Для восстановления доставки
    | и вызовов внешних ТК установите PHYSICAL_SHIPPING_ENABLED=true в .env
    */
    'marketplace' => [
        'physical_shipping_enabled' => env('PHYSICAL_SHIPPING_ENABLED', false),
    ],

    'cdek' => [
        'client_id' => env('CDEK_CLIENT_ID'),
        'client_secret' => env('CDEK_CLIENT_SECRET'),
        'api_url' => env('CDEK_API_URL', 'https://api.cdek.ru/v2'),
        'test_mode' => env('CDEK_TEST_MODE', true),
        
        // Данные отправителя
        'sender_name' => env('CDEK_SENDER_NAME', 'SanCan'),
        'sender_phone' => env('CDEK_SENDER_PHONE', '+79999999999'),
        'sender_city' => env('CDEK_SENDER_CITY', 'Москва'),
        'sender_address' => env('CDEK_SENDER_ADDRESS', 'ул. Примерная, д. 1'),
        'shipper_name' => env('CDEK_SHIPPER_NAME', 'SanCan Интернет-магазин'),
    ],

    'yandex_delivery' => [
        'api_key' => env('YANDEX_DELIVERY_API_KEY'),
        'api_url' => env('YANDEX_DELIVERY_API_URL', 'https://b2b.taxi.yandex.net/api/delivery/v1'),
        'test_mode' => env('YANDEX_DELIVERY_TEST_MODE', true),
    ],

    'maxmind_database' => [
        'account_id' => env('MAXMIND_ACCOUNT_ID'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
        'database' => env('MAXMIND_DATABASE', 'GeoLite2-City'),
    ],

    'dadata' => [
        'api_key' => env('DADATA_API_KEY'),
        'secret_key' => env('DADATA_SECRET_KEY'),
        'api_url' => env('DADATA_API_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs'),
        'city_fallback_enabled' => env('DADATA_CITY_FALLBACK_ENABLED', true),
    ],

    'yandex_geocoder' => [
        // Используем тот же ключ, что и для Locator
        'api_key' => env('YANDEX_GEO_API_KEY'),
        'api_url' => env('YANDEX_GEOCODER_API_URL', 'https://geocode-maps.yandex.ru/1.x/'),
    ],

    'yandex_locator' => [
        // Используем тот же ключ для обоих сервисов
        'api_key' => env('YANDEX_GEO_API_KEY'),
        'api_url' => env('YANDEX_LOCATOR_API_URL', 'https://locator.api.maps.yandex.ru/v1/locate'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Yandex OAuth 2.0 Configuration
    |--------------------------------------------------------------------------
    |
    | Конфигурация для авторизации через Яндекс OAuth 2.0
    | Используется пакет SocialiteProviders/Yandex
    |
    | Получить CLIENT_ID и CLIENT_SECRET можно на странице:
    | https://oauth.yandex.ru/
    |
    */
    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect' => env('YANDEX_REDIRECT_URI', 'https://api.treabo.md/api/auth/oauth/yandex/callback'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'https://api.treabo.md/api/auth/oauth/google/callback'),
    ],

];
