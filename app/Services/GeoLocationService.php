<?php

namespace App\Services;

use Torann\GeoIP\Location;
use App\Models\GeoIpCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class GeoLocationService
{
    private $dadataClient;
    private $yandexApiKey;
    private $yandexGeoService;
    
    private $dadataApiKey;
    private $dadataSecretKey;
    private $dadataApiUrl;
    
    public function __construct()
    {
        // Инициализируем DaData конфигурацию (используем прямые HTTP запросы)
        $this->dadataApiKey = config('services.dadata.api_key');
        $this->dadataSecretKey = config('services.dadata.secret_key');
        $this->dadataApiUrl = config('services.dadata.api_url', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs');
        
        // Проверяем наличие ключей
        if ($this->dadataApiKey && $this->dadataSecretKey) {
            Log::info('GeoLocationService: DaData configured', [
                'api_key_length' => strlen($this->dadataApiKey),
                'secret_key_length' => strlen($this->dadataSecretKey),
                'api_url' => $this->dadataApiUrl
            ]);
            // Используем прямые HTTP запросы вместо SDK
            $this->dadataClient = true; // Флаг что DaData настроен
        } else {
            Log::warning('GeoLocationService: DaData API keys not configured', [
                'has_api_key' => !empty($this->dadataApiKey),
                'has_secret_key' => !empty($this->dadataSecretKey)
            ]);
            $this->dadataClient = null;
        }
        
        // Yandex сервисы (опционально, код сохранен для будущего использования)
        $this->yandexApiKey = config('services.yandex_geocoder.api_key');
        
        // Инициализируем YandexGeoService с обработкой ошибок (опционально)
        try {
            // Проверяем, включен ли Yandex (можно отключить через конфиг)
            if (config('services.yandex_locator.enabled', false)) {
                $this->yandexGeoService = new YandexGeoService();
            } else {
                $this->yandexGeoService = null;
                Log::debug('GeoLocationService: Yandex services disabled (optional)');
            }
        } catch (\Exception $e) {
            Log::debug('GeoLocationService: YandexGeoService not available (optional)', [
                'message' => $e->getMessage()
            ]);
            $this->yandexGeoService = null;
        }
    }

    /**
     * Получить местоположение по IP адресу с гибридной логикой
     * 
     * @param string|null $ip IP адрес (если не указан, определяется автоматически)
     * @param array $wifi Массив WiFi точек для Yandex Locator (опционально)
     * @param array $cell Массив Cell данных для Yandex Locator (опционально)
     * @param array|null $coordinates Координаты от HTML5 Geolocation [lat, lon] (опционально)
     * @return array Данные о местоположении
     */
    public function getLocationByIp(string $ip = null, array $wifi = [], array $cell = [], ?array $coordinates = null): array
    {
        try {
            $ip = $ip ?: $this->getClientIp();
            
            // ШАГ 1: Проверяем БД перед запросом к DaData (экономия лимита)
            // Рекомендация DaData: "Запоминать результат, который вернула «Дадата» — и не делать повторных вызовов"
            // НО: если в кэше только MaxMind результат (английский), а DaData доступен - пробуем DaData для русского языка
            $cachedLocation = GeoIpCache::findByIp($ip);
            if ($cachedLocation && ($cachedLocation->source === 'dadata' || strpos($cachedLocation->source, 'dadata') === 0)) {
                // Если в кэше есть результат DaData (русский) - используем его
                $cachedLocation->incrementUsage();
                
                Log::debug('GeoIP: Using cached DaData location from database', [
                    'ip' => $ip,
                    'city' => $cachedLocation->city,
                    'source' => $cachedLocation->source,
                    'request_count' => $cachedLocation->request_count
                ]);
                
                return $cachedLocation->toLocationArray();
            }
            
            // Если в кэше только MaxMind (английский), но DaData доступен - пробуем DaData для русского языка
            // Это важно для российского сайта - приоритет русскоязычным данным
            
            // ШАГ 2: Проверяем на бота перед запросом к DaData
            // Для ботов используем только бесплатный MaxMind, чтобы не тратить лимит DaData
            // Это экономит лимит DaData для реальных пользователей (которым нужны русскоязычные данные)
            $isBot = $this->isBotRequest();
            if ($isBot) {
                Log::debug('GeoIP: Bot detected, using MaxMind only (saving DaData quota for real users)', [
                    'ip' => $ip,
                    'note' => 'Bots get English data from MaxMind, real users get Russian data from DaData'
                ]);
                $maxmindLocation = $this->getMaxMindLocation($ip);
                
                // Сохраняем в БД даже для ботов (MaxMind результат)
                if ($maxmindLocation && isset($maxmindLocation['city'])) {
                    try {
                        GeoIpCache::saveLocation($ip, $maxmindLocation);
                    } catch (\Exception $e) {
                        Log::warning('GeoIP: Failed to save MaxMind location to DB', [
                            'ip' => $ip,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                return $maxmindLocation;
            }
            
            // ШАГ 3: Для реальных пользователей используем гибридную систему
            // Ключ кэша зависит от наличия WiFi/Cell/координат для точности
            // Кэшируем на 1 час в Redis для быстрого доступа
            $cacheKey = "geo_location_{$ip}_" . md5(json_encode(['wifi' => $wifi, 'cell' => $cell, 'coordinates' => $coordinates]));
            
            return Cache::remember($cacheKey, 3600, function () use ($ip, $wifi, $cell, $coordinates) {
                // ГИБРИДНАЯ СИСТЕМА: DaData (русский) -> MaxMind (английский, запасной) -> Yandex (опционально)
                // Приоритет: DaData для русскоязычных данных, MaxMind только если DaData недоступен или лимит исчерпан
                
                // Уровень 1: DaData.ru - ОСНОВНОЙ сервис (возвращает данные на русском языке)
                // Используется первым для российского сайта
                if ($this->dadataClient) {
                    try {
                        $dadataLocation = $this->getDaDataLocationByIp($ip);
                        if ($dadataLocation && isset($dadataLocation['city'])) {
                            // Сохраняем результат в БД для будущих запросов
                            try {
                                GeoIpCache::saveLocation($ip, $dadataLocation);
                                Log::info('GeoIP: Saved DaData location to database (Russian)', [
                                    'ip' => $ip,
                                    'city' => $dadataLocation['city'],
                                    'country' => $dadataLocation['country']
                                ]);
                            } catch (\Exception $e) {
                                Log::warning('GeoIP: Failed to save DaData location to DB', [
                                    'ip' => $ip,
                                    'error' => $e->getMessage()
                                ]);
                            }
                            
                            Log::info('GeoIP: Using DaData (primary service, Russian)', [
                                'ip' => $ip,
                                'city' => $dadataLocation['city'],
                                'country' => $dadataLocation['country']
                            ]);
                            return $dadataLocation;
                        }
                    } catch (\Exception $e) {
                        // Если DaData недоступен или лимит исчерпан - используем MaxMind как запасной
                        Log::warning('GeoIP: DaData unavailable (limit exceeded or error), falling back to MaxMind', [
                            'message' => $e->getMessage(),
                            'ip' => $ip,
                            'note' => 'MaxMind will return English data as fallback'
                        ]);
                    }
                }
                
                // Уровень 2: MaxMind GeoIP - ЗАПАСНОЙ сервис (возвращает данные на английском языке)
                // Используется только если DaData недоступен или лимит исчерпан
                $maxmindLocation = $this->getMaxMindLocation($ip);
                
                // Сохраняем MaxMind результат в БД (только если DaData не сработал)
                // Важно: MaxMind возвращает данные на английском, поэтому это запасной вариант
                if ($maxmindLocation && isset($maxmindLocation['city'])) {
                    try {
                        GeoIpCache::saveLocation($ip, $maxmindLocation);
                        Log::info('GeoIP: Saved MaxMind location to database (fallback, English)', [
                            'ip' => $ip,
                            'city' => $maxmindLocation['city'],
                            'note' => 'DaData was unavailable, using MaxMind as fallback'
                        ]);
                    } catch (\Exception $e) {
                        Log::warning('GeoIP: Failed to save MaxMind location to DB', [
                            'ip' => $ip,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Если есть координаты от HTML5 Geolocation, используем их напрямую (максимальная точность)
                if ($coordinates && isset($coordinates['lat']) && isset($coordinates['lon'])) {
                    // Используем DaData для обратного геокодинга, если доступен
                    if ($this->dadataClient) {
                        $reverseLocation = $this->getDaDataLocationByCoordinates(
                            (float) $coordinates['lat'],
                            (float) $coordinates['lon'],
                            null
                        );
                        if ($reverseLocation) {
                            $enhancedLocation = array_merge($maxmindLocation, [
                                'lat' => (float) $coordinates['lat'],
                                'lon' => (float) $coordinates['lon'],
                                'city' => $reverseLocation['city'] ?? $maxmindLocation['city'],
                                'state_name' => $reverseLocation['state_name'] ?? $maxmindLocation['state_name'],
                                'country' => $reverseLocation['country'] ?? $maxmindLocation['country'],
                                'iso_code' => $reverseLocation['iso_code'] ?? $maxmindLocation['iso_code'],
                                'source' => 'html5_geolocation_dadata',
                                'base_source' => $maxmindLocation['source'] ?? 'maxmind',
                                'accuracy' => $coordinates['accuracy'] ?? null
                            ]);
                            
                            Log::info('GeoIP: Using HTML5 Geolocation with DaData', [
                                'ip' => $ip,
                                'city' => $enhancedLocation['city']
                            ]);
                            return $enhancedLocation;
                        }
                    }
                    
                    // Fallback на Yandex для обратного геокодинга (если включен)
                    if ($this->yandexApiKey) {
                        $reverseLocation = $this->getYandexLocation(
                            (float) $coordinates['lat'], 
                            (float) $coordinates['lon']
                        );
                        if ($reverseLocation) {
                            $enhancedLocation = array_merge($maxmindLocation, [
                                'lat' => (float) $coordinates['lat'],
                                'lon' => (float) $coordinates['lon'],
                                'city' => $reverseLocation['city'] ?? $maxmindLocation['city'],
                                'state_name' => $reverseLocation['state_name'] ?? $maxmindLocation['state_name'],
                                'country' => $reverseLocation['country'] ?? $maxmindLocation['country'],
                                'iso_code' => $reverseLocation['iso_code'] ?? $maxmindLocation['iso_code'],
                                'source' => 'html5_geolocation_yandex',
                                'base_source' => $maxmindLocation['source'] ?? 'maxmind',
                                'accuracy' => $coordinates['accuracy'] ?? null
                            ]);
                            return $enhancedLocation;
                        }
                    }
                }
                
                // Уровень 3: Yandex Locator (опционально, если включен)
                if ($this->yandexGeoService && config('services.yandex_locator.enabled', false)) {
                    try {
                        $yandexLocation = $this->yandexGeoService->getLocationByIp($ip, $wifi, $cell);
                        if ($yandexLocation && isset($yandexLocation['lat']) && isset($yandexLocation['lon'])) {
                            // Используем DaData для обратного геокодинга
                            if ($this->dadataClient) {
                                $reverseLocation = $this->getDaDataLocationByCoordinates(
                                    $yandexLocation['lat'],
                                    $yandexLocation['lon'],
                                    null
                                );
                                if ($reverseLocation) {
                                    $yandexLocation = array_merge($yandexLocation, $reverseLocation);
                                }
                            }
                            
                            $enhancedLocation = array_merge($maxmindLocation, [
                                'lat' => $yandexLocation['lat'],
                                'lon' => $yandexLocation['lon'],
                                'city' => $yandexLocation['city'] ?? $maxmindLocation['city'],
                                'state_name' => $yandexLocation['state_name'] ?? $maxmindLocation['state_name'],
                                'country' => $yandexLocation['country'] ?? $maxmindLocation['country'],
                                'iso_code' => $yandexLocation['iso_code'] ?? $maxmindLocation['iso_code'],
                                'source' => 'hybrid_yandex_dadata',
                                'base_source' => $maxmindLocation['source'] ?? 'maxmind',
                                'accuracy' => $yandexLocation['accuracy'] ?? null
                            ]);
                            
                            Log::info('GeoIP: Using hybrid Yandex Locator with DaData', [
                                'ip' => $ip,
                                'city' => $enhancedLocation['city']
                            ]);
                            return $enhancedLocation;
                        }
                    } catch (\Exception $e) {
                        Log::debug('GeoIP: Yandex Locator error (optional)', [
                            'message' => $e->getMessage(),
                            'ip' => $ip
                        ]);
                    }
                }
                
                // Возвращаем базовое местоположение от MaxMind
                return $maxmindLocation;
            });
        } catch (\Exception $e) {
            Log::error('GeoIP: Error in getLocationByIp', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $ip ?? 'unknown'
            ]);
            
            // Возвращаем дефолтное местоположение
            return $this->getDefaultLocation($ip ?? '127.0.0.1');
        }
    }

    /**
     * Получить местоположение через MaxMind
     */
    private function getMaxMindLocation(string $ip): array
    {
        try {
            // Проверяем, существует ли база данных
            $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');
            
            if (!file_exists($dbPath)) {
                Log::warning('MaxMind database not found', ['path' => $dbPath]);
                return $this->getDefaultLocation($ip);
            }
            
            // geoip($ip) уже возвращает объект Location
            $location = geoip($ip);
            
            return [
                'ip' => $location->ip ?? $ip,
                'country' => $location->country ?? 'Unknown',
                'iso_code' => $location->iso_code ?? 'Unknown',
                'city' => $location->city ?? 'Unknown',
                'state' => $location->state ?? null,
                'state_name' => $location->state_name ?? null,
                'postal_code' => $location->postal_code ?? null,
                'lat' => $location->lat ?? 0,
                'lon' => $location->lon ?? 0,
                'timezone' => $location->timezone ?? 'UTC',
                'continent' => $location->continent ?? 'Unknown',
                'currency' => $location->currency ?? 'USD',
                'source' => 'maxmind'
            ];
        } catch (\Exception $e) {
            Log::error('MaxMind GeoIP error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $ip
            ]);
            return $this->getDefaultLocation($ip);
        }
    }

    /**
     * Получить местоположение по IP через DaData API (основной метод)
     * Документация: https://dadata.ru/api/iplocate/
     * 
     * Важно: Результаты кэшируются на 1 час для экономии лимита запросов (10 000/день бесплатно)
     * Рекомендация DaData: запоминать результат и не делать повторных вызовов
     */
    private function getDaDataLocationByIp(string $ip): ?array
    {
        try {
            if (!$this->dadataClient || !$this->dadataApiKey || !$this->dadataSecretKey) {
                return null;
            }
            
            // Прямой HTTP запрос к DaData API
            // Документация: https://dadata.ru/api/iplocate/
            // POST https://suggestions.dadata.ru/suggestions/api/4_1/rs/iplocate/address
            // Headers: Authorization: Token {API_KEY}, Content-Type: application/json
            $url = $this->dadataApiUrl . '/iplocate/address';
            
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, ['ip' => $ip]);
            
            if (!$response->successful()) {
                Log::warning('DaData iplocate: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'ip' => $ip
                ]);
                return null;
            }
            
            $result = $response->json();
            
            // Проверяем ответ согласно документации
            // Если город не определен, location = null
            if ($result && isset($result['location']) && $result['location'] !== null) {
                $locationData = $result['location']['data'] ?? null;
                
                if ($locationData) {
                    // Получаем координаты из ответа или через геокодинг
                    $lat = null;
                    $lon = null;
                    
                    if (isset($locationData['geo_lat']) && isset($locationData['geo_lon'])) {
                        $lat = (float) $locationData['geo_lat'];
                        $lon = (float) $locationData['geo_lon'];
                    } else {
                        // Если координат нет в ответе, пытаемся получить через геокодинг адреса
                        $addressQuery = $result['location']['value'] ?? $result['location']['unrestricted_value'] ?? null;
                        if ($addressQuery) {
                            $geoResult = $this->getDaDataLocationByCoordinates(null, null, $addressQuery);
                            if ($geoResult) {
                                $lat = $geoResult['lat'] ?? null;
                                $lon = $geoResult['lon'] ?? null;
                            }
                        }
                    }
                    
                    // Формируем результат со всеми доступными полями из документации
                    $location = [
                        'ip' => $ip,
                        'city' => $locationData['city'] ?? null,
                        'city_with_type' => $locationData['city_with_type'] ?? null,
                        'city_type' => $locationData['city_type'] ?? null,
                        'city_type_full' => $locationData['city_type_full'] ?? null,
                        'region' => $locationData['region'] ?? null,
                        'region_with_type' => $locationData['region_with_type'] ?? null,
                        'region_type' => $locationData['region_type'] ?? null,
                        'region_type_full' => $locationData['region_type_full'] ?? null,
                        'state_name' => $locationData['region_with_type'] ?? $locationData['region'] ?? null,
                        'federal_district' => $locationData['federal_district'] ?? null,
                        'country' => $locationData['country'] ?? 'Россия',
                        'iso_code' => $locationData['country_iso_code'] ?? 'RU',
                        'region_iso_code' => $locationData['region_iso_code'] ?? null,
                        'postal_code' => $locationData['postal_code'] ?? null,
                        'lat' => $lat,
                        'lon' => $lon,
                        'kladr_id' => $locationData['city_kladr_id'] ?? $locationData['region_kladr_id'] ?? null,
                        'city_kladr_id' => $locationData['city_kladr_id'] ?? null,
                        'region_kladr_id' => $locationData['region_kladr_id'] ?? null,
                        'fias_id' => $locationData['city_fias_id'] ?? $locationData['region_fias_id'] ?? null,
                        'city_fias_id' => $locationData['city_fias_id'] ?? null,
                        'region_fias_id' => $locationData['region_fias_id'] ?? null,
                        'timezone' => ($lat !== null && $lon !== null) ? $this->getTimezoneByCoordinates($lat, $lon) : 'UTC',
                        'source' => 'dadata',
                        'full_address' => $result['location']['value'] ?? null,
                        'unrestricted_value' => $result['location']['unrestricted_value'] ?? null
                    ];
                    
                    Log::debug('DaData: Location found', [
                        'ip' => $ip,
                        'city' => $location['city'],
                        'country' => $location['country'],
                        'has_coordinates' => ($lat !== null && $lon !== null)
                    ]);
                    
                    return $location;
                }
            } else {
                // location = null означает, что город не удалось определить
                // Точность определения города по IP: 60-80% согласно документации
                Log::debug('DaData: City not found for IP (location = null)', [
                    'ip' => $ip,
                    'message' => 'DaData не смогла определить город для данного IP. Точность определения по IP: 60-80%'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('DaData IP location error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'ip' => $ip,
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        return null;
    }
    
    /**
     * Получить местоположение по координатам через DaData (обратный геокодинг)
     * Или по адресу (если передан $addressQuery)
     */
    private function getDaDataLocationByCoordinates(?float $lat = null, ?float $lon = null, ?string $addressQuery = null): ?array
    {
        try {
            if (!$this->dadataClient || !$this->dadataApiKey || !$this->dadataSecretKey) {
                return null;
            }
            
            $query = null;
            if ($addressQuery) {
                $query = $addressQuery;
            } elseif ($lat !== null && $lon !== null) {
                // Обратный геокодинг по координатам
                $query = "{$lon},{$lat}";
            } else {
                return null;
            }
            
            // Прямой HTTP запрос к DaData API
            $url = $this->dadataApiUrl . '/suggest/address';
            
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, [
                    'query' => $query,
                    'count' => 1
                ]);
            
            if (!$response->successful()) {
                Log::warning('DaData reverse geocoding: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'query' => $query
                ]);
                return null;
            }
            
            $result = $response->json();
            $suggestions = $result['suggestions'] ?? [];
            
            if (!empty($suggestions)) {
                $suggestion = $suggestions[0];
                $data = $suggestion['data'] ?? [];
                
                return [
                    'city' => $data['city'] ?? null,
                    'state_name' => $data['region_with_type'] ?? $data['region'] ?? null,
                    'country' => $data['country'] ?? 'Россия',
                    'iso_code' => $data['country_iso_code'] ?? 'RU',
                    'postal_code' => $data['postal_code'] ?? null,
                    'kladr_id' => $data['city_kladr_id'] ?? $data['region_kladr_id'] ?? null,
                    'fias_id' => $data['city_fias_id'] ?? $data['region_fias_id'] ?? null,
                    'lat' => isset($data['geo_lat']) ? (float) $data['geo_lat'] : $lat,
                    'lon' => isset($data['geo_lon']) ? (float) $data['geo_lon'] : $lon,
                    'full_address' => $suggestion['value'] ?? null
                ];
            }
        } catch (\Exception $e) {
            Log::warning('DaData reverse geocoding error', [
                'message' => $e->getMessage(),
                'lat' => $lat,
                'lon' => $lon,
                'address_query' => $addressQuery
            ]);
        }
        
        return null;
    }
    
    /**
     * Получить местоположение через российские сервисы (старый метод, оставлен для совместимости)
     */
    private function getRussianLocation(string $ip, array $maxmindLocation): ?array
    {
        try {
            // Сначала пытаемся получить более точные данные через внешние API
            $externalLocation = $this->getExternalLocation($ip);
            
            if ($externalLocation) {
                // Валидируем через DaData
                $validatedLocation = $this->validateWithDaData($externalLocation);
                
                if ($validatedLocation) {
                    return array_merge($validatedLocation, ['source' => 'russian_services']);
                }
            }
            
            // Если внешние сервисы недоступны, используем MaxMind с улучшениями
            return $this->enhanceMaxMindWithRussianData($maxmindLocation);
            
        } catch (\Exception $e) {
            Log::error('Russian services error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Получить местоположение через внешние API (ipapi.co, ipinfo.io)
     */
    private function getExternalLocation(string $ip): ?array
    {
        try {
            // Пробуем ipapi.co
            $response = Http::timeout(5)->get("http://ipapi.co/{$ip}/json/");
            
            if ($response->successful()) {
                $data = $response->json();
                
                if ($data && !isset($data['error'])) {
                    return [
                        'ip' => $data['ip'] ?? $ip,
                        'country' => $data['country_name'] ?? 'Unknown',
                        'iso_code' => $data['country_code'] ?? 'Unknown',
                        'city' => $data['city'] ?? null,
                        'state' => $data['region_code'] ?? null,
                        'state_name' => $data['region'] ?? null,
                        'postal_code' => $data['postal'] ?? null,
                        'lat' => $data['latitude'] ?? 0,
                        'lon' => $data['longitude'] ?? 0,
                        'timezone' => $data['timezone'] ?? 'UTC',
                        'continent' => $data['continent_code'] ?? 'Unknown',
                        'currency' => $data['currency'] ?? 'USD',
                        'source' => 'ipapi'
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('ipapi.co error: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Валидировать адрес через DaData
     */
    private function validateWithDaData(array $location): ?array
    {
        try {
            if (!$this->dadataClient) {
                return $location; // Если DaData не настроен, возвращаем исходные данные
            }
            
            if (!$location['city'] || !$location['state_name']) {
                return $location;
            }
            
            $query = "{$location['city']}, {$location['state_name']}";
            
            // Прямой HTTP запрос к DaData API
            $url = $this->dadataApiUrl . '/suggest/address';
            
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, [
                    'query' => $query,
                    'count' => 1
                ]);
            
            if ($response->successful()) {
                $result = $response->json();
                $suggestions = $result['suggestions'] ?? [];
                
                if (!empty($suggestions)) {
                    $suggestion = $suggestions[0];
                    $data = $suggestion['data'] ?? [];
                    
                    return array_merge($location, [
                        'city' => $data['city'] ?? $location['city'],
                        'state_name' => $data['region_with_type'] ?? $location['state_name'],
                        'postal_code' => $data['postal_code'] ?? $location['postal_code'],
                        'kladr_id' => $data['kladr_id'] ?? null,
                        'fias_id' => $data['fias_id'] ?? null,
                        'geo_lat' => $data['geo_lat'] ?? $location['lat'],
                        'geo_lon' => $data['geo_lon'] ?? $location['lon'],
                        'source' => 'dadata_validated'
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('DaData validation error: ' . $e->getMessage());
        }
        
        return $location;
    }

    /**
     * Улучшить данные MaxMind с российскими данными
     */
    private function enhanceMaxMindWithRussianData(array $maxmindLocation): array
    {
        try {
            // Если есть координаты, пытаемся получить адрес через Яндекс.Геокодер
            if ($maxmindLocation['lat'] && $maxmindLocation['lon']) {
                $yandexLocation = $this->getYandexLocation($maxmindLocation['lat'], $maxmindLocation['lon']);
                
                if ($yandexLocation) {
                    return array_merge($maxmindLocation, $yandexLocation, ['source' => 'maxmind_yandex']);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Yandex Geocoder error: ' . $e->getMessage());
        }
        
        return $maxmindLocation;
    }

    /**
     * Получить адрес через Яндекс.Геокодер
     */
    private function getYandexLocation(float $lat, float $lon): ?array
    {
        try {
            if (!$this->yandexApiKey) {
                return null;
            }
            
            $response = Http::timeout(5)->get('https://geocode-maps.yandex.ru/1.x/', [
                'apikey' => $this->yandexApiKey,
                'geocode' => "{$lon},{$lat}",
                'format' => 'json',
                'results' => 1
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['response']['GeoObjectCollection']['featureMember'][0])) {
                    $geoObject = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject'];
                    $components = $geoObject['metaDataProperty']['GeocoderMetaData']['Address']['Components'];
                    
                    $city = null;
                    $region = null;
                    
                    foreach ($components as $component) {
                        if ($component['kind'] === 'locality') {
                            $city = $component['name'];
                        } elseif ($component['kind'] === 'province') {
                            $region = $component['name'];
                        }
                    }
                    
                    return [
                        'city' => $city,
                        'state_name' => $region,
                        'yandex_address' => $geoObject['metaDataProperty']['GeocoderMetaData']['text'] ?? null
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::warning('Yandex Geocoder error: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Определить, нужно ли использовать российские сервисы
     */
    private function shouldUseRussianServices(array $location): bool
    {
        $russianCountries = ['RU', 'BY', 'KZ', 'UA'];
        return in_array($location['iso_code'], $russianCountries);
    }

    /**
     * Получить местоположение по IP адресу (старый метод для совместимости)
     */
    public function getLocationByIpLegacy(string $ip = null): Location
    {
        return geoip($ip);
    }

    /**
     * Получить страну по IP
     */
    public function getCountryByIp(string $ip = null): string
    {
        try {
            $location = $this->getLocationByIp($ip);
            return $location['country'] ?? 'Unknown';
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    /**
     * Получить город по IP
     */
    public function getCityByIp(string $ip = null): string
    {
        $location = $this->getLocationByIp($ip);
        return $location['city'] ?? 'Unknown';
    }

    /**
     * Получить координаты по IP
     */
    public function getCoordinatesByIp(string $ip = null): array
    {
        $location = $this->getLocationByIp($ip);
        return [
            'lat' => $location['lat'] ?? 0,
            'lon' => $location['lon'] ?? 0,
        ];
    }

    /**
     * Проверить, является ли IP российским
     */
    public function isRussianIp(string $ip = null): bool
    {
        try {
            $location = $this->getLocationByIp($ip);
            return $location['iso_code'] === 'RU';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Получить полную информацию о местоположении
     */
    public function getFullLocationInfo(string $ip = null): array
    {
        return $this->getLocationByIp($ip);
    }

    /**
     * Поиск городов через DaData (для автодополнения)
     */
    public function searchCities(string $query): array
    {
        try {
            if (!$this->dadataClient) {
                return [
                    'cities' => [],
                    'total' => 0,
                    'error' => 'DaData не настроен'
                ];
            }
            
            // Прямой HTTP запрос к DaData API для поиска городов
            $url = $this->dadataApiUrl . '/suggest/address';
            
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, [
                    'query' => $query,
                    'count' => 10,
                    'locations' => [
                        ['country_iso_code' => 'RU']
                    ],
                    'from_bound' => ['value' => 'city'],
                    'to_bound' => ['value' => 'city']
                ]);
            
            if (!$response->successful()) {
                Log::warning('DaData city search: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'query' => $query
                ]);
                return [
                    'cities' => [],
                    'total' => 0,
                    'error' => 'Ошибка запроса к DaData API'
                ];
            }
            
            $result = $response->json();
            $suggestions = $result['suggestions'] ?? [];
            
            $cities = [];
            $seen = [];
            
            foreach ($suggestions as $suggestion) {
                $data = $suggestion['data'] ?? [];
                $city = $data['city'] ?? null;
                
                if ($city) {
                    $key = strtolower($city);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        
                        $cities[] = [
                            'name' => $city,
                            'region' => $data['region_with_type'] ?? $data['region'] ?? null,
                            'country' => $data['country'] ?? 'Россия',
                            'full_name' => trim(implode(', ', array_filter([$city, $data['region_with_type'] ?? $data['region'] ?? null, $data['country'] ?? 'Россия']))),
                            'lat' => isset($data['geo_lat']) ? (float) $data['geo_lat'] : null,
                            'lon' => isset($data['geo_lon']) ? (float) $data['geo_lon'] : null,
                            'address' => $suggestion['value'] ?? $city,
                            'kladr_id' => $data['kladr_id'] ?? null,
                            'fias_id' => $data['fias_id'] ?? null
                        ];
                    }
                }
            }
            
            return [
                'cities' => $cities,
                'total' => count($cities),
                'query' => $query
            ];
        } catch (\Exception $e) {
            Log::error('DaData city search error', [
                'message' => $e->getMessage(),
                'query' => $query
            ]);
            return [
                'cities' => [],
                'total' => 0,
                'error' => 'Ошибка поиска городов'
            ];
        }
    }

    /**
     * Поиск полных адресов через DaData API (город, улица, дом)
     * Документация: https://dadata.ru/api/suggest/address/
     */
    public function searchAddresses(string $query, int $count = 10): array
    {
        try {
            if (!$this->dadataClient || !$this->dadataApiKey || !$this->dadataSecretKey) {
                $hasApiKey = !empty($this->dadataApiKey);
                $hasSecretKey = !empty($this->dadataSecretKey);
                
                Log::error('DaData не настроен', [
                    'query' => $query,
                    'has_api_key' => $hasApiKey,
                    'has_secret_key' => $hasSecretKey,
                    'config_services_dadata' => config('services.dadata')
                ]);
                
                $errorMsg = 'DaData API ключи не настроены в .env (DADATA_API_KEY, DADATA_SECRET_KEY)';
                
                return [
                    'addresses' => [],
                    'total' => 0,
                    'error' => $errorMsg
                ];
            }
            
            Log::debug('DaData: Начало поиска адресов', [
                'query' => $query,
                'count' => $count,
                'url' => $this->dadataApiUrl . '/suggest/address'
            ]);
            
            // Прямой HTTP запрос к DaData API для поиска адресов
            // Документация: https://dadata.ru/api/suggest/address/
            // POST https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address
            // Headers: Authorization: Token {API_KEY}, Content-Type: application/json
            $url = $this->dadataApiUrl . '/suggest/address';
            
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, [
                    'query' => $query,
                    'count' => min($count, 20) // Максимум 20 по документации
                ]);
            
            if (!$response->successful()) {
                Log::error('DaData suggest: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'query' => $query,
                    'url' => $url
                ]);
                return [
                    'addresses' => [],
                    'total' => 0,
                    'error' => 'Ошибка запроса к DaData API: HTTP ' . $response->status()
                ];
            }
            
            $result = $response->json();
            $suggestions = $result['suggestions'] ?? [];
            
            Log::debug('DaData: Получены подсказки', [
                'query' => $query,
                'suggestions_count' => is_array($suggestions) ? count($suggestions) : 0
            ]);
            
            if (!is_array($suggestions)) {
                Log::warning('DaData: Неверный формат ответа', [
                    'query' => $query,
                    'type' => gettype($suggestions),
                    'result' => $result
                ]);
                return [
                    'addresses' => [],
                    'total' => 0,
                    'error' => 'Неверный формат ответа от DaData'
                ];
            }
            
            $addresses = [];
            
            foreach ($suggestions as $suggestion) {
                if (!isset($suggestion['data'])) {
                    Log::warning('DaData: Пропущена подсказка без data', ['suggestion' => $suggestion]);
                    continue;
                }
                
                $data = $suggestion['data'];
                
                $addresses[] = [
                    'value' => $suggestion['value'] ?? '', // Полный адрес
                    'unrestricted_value' => $suggestion['unrestricted_value'] ?? '',
                    'city' => $data['city'] ?? null,
                    'city_with_type' => $data['city_with_type'] ?? null,
                    'region' => $data['region'] ?? null,
                    'region_with_type' => $data['region_with_type'] ?? null,
                    'country' => $data['country'] ?? 'Россия',
                    'street' => $data['street'] ?? null,
                    'street_with_type' => $data['street_with_type'] ?? null,
                    'house' => $data['house'] ?? null,
                    'block' => $data['block'] ?? null,
                    'flat' => $data['flat'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'lat' => isset($data['geo_lat']) ? (float) $data['geo_lat'] : null,
                    'lon' => isset($data['geo_lon']) ? (float) $data['geo_lon'] : null,
                    'kladr_id' => $data['kladr_id'] ?? null,
                    'fias_id' => $data['fias_id'] ?? null,
                    'city_kladr_id' => $data['city_kladr_id'] ?? null,
                    'city_fias_id' => $data['city_fias_id'] ?? null,
                    // Для совместимости с фронтендом
                    'name' => $data['city'] ?? '',
                    'full_name' => $suggestion['value'] ?? '',
                    'address' => $suggestion['value'] ?? ''
                ];
            }
            
            Log::info('DaData: Поиск завершен', [
                'query' => $query,
                'found' => count($addresses)
            ]);
            
            return [
                'addresses' => $addresses,
                'total' => count($addresses),
                'query' => $query
            ];
        } catch (\Exception $e) {
            Log::error('DaData address search error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'query' => $query,
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'addresses' => [],
                'total' => 0,
                'error' => 'Ошибка поиска адресов: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Получить местоположение по КЛАДР через DaData
     */
    public function getLocationByKladr(string $query): array
    {
        try {
            if (!$this->dadataClient || !$this->dadataApiKey || !$this->dadataSecretKey) {
                return [
                    'addresses' => [],
                    'total' => 0,
                    'error' => 'DaData не настроен. Проверьте DADATA_API_KEY и DADATA_SECRET_KEY в .env'
                ];
            }
            
            // Прямой HTTP запрос к DaData API
            $url = $this->dadataApiUrl . '/suggest/address';
            
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, [
                    'query' => $query,
                    'count' => 10
                ]);
            
            if (!$response->successful()) {
                Log::warning('DaData KLADR search: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'query' => $query
                ]);
                return [
                    'addresses' => [],
                    'total' => 0,
                    'error' => 'Ошибка запроса к DaData API'
                ];
            }
            
            $result = $response->json();
            $suggestions = $result['suggestions'] ?? [];
            
            $addresses = [];
            foreach ($suggestions as $suggestion) {
                $data = $suggestion['data'] ?? [];
                $addresses[] = [
                    'id' => $data['kladr_id'] ?? null,
                    'full_address' => $suggestion['value'] ?? '',
                    'city' => $data['city'] ?? null,
                    'street' => $data['street'] ?? null,
                    'house' => $data['house'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'latitude' => $data['geo_lat'] ?? null,
                    'longitude' => $data['geo_lon'] ?? null,
                    'kladr_id' => $data['kladr_id'] ?? null,
                    'fias_id' => $data['fias_id'] ?? null,
                    'region' => $data['region_with_type'] ?? null,
                    'district' => $data['city_district_with_type'] ?? null
                ];
            }
            
            return [
                'addresses' => $addresses,
                'total' => count($addresses),
                'query' => $query
            ];
        } catch (\Exception $e) {
            Log::error('DaData KLADR search error: ' . $e->getMessage());
            return [
                'addresses' => [],
                'total' => 0,
                'error' => 'Ошибка поиска по КЛАДР'
            ];
        }
    }

    /**
     * Получить местоположение по координатам через Яндекс.Геокодер
     */
    public function getLocationByCoordinates(float $lat, float $lon): ?array
    {
        return $this->getYandexLocation($lat, $lon);
    }

    /**
     * Получить таймзону по координатам (упрощенная версия)
     * 
     * @param float $lat Широта
     * @param float $lon Долгота
     * @return string Таймзона
     */
    private function getTimezoneByCoordinates(float $lat, float $lon): string
    {
        try {
            // Для России всегда Europe/Moscow
            if ($lon > 20 && $lon < 180 && $lat > 41 && $lat < 82) {
                return 'Europe/Moscow';
            }
            
            // Для других регионов возвращаем UTC
            return 'UTC';
        } catch (\Exception $e) {
            Log::debug('Timezone detection error', [
                'lat' => $lat,
                'lon' => $lon,
                'error' => $e->getMessage()
            ]);
            return 'UTC';
        }
    }

    /**
     * Получить дефолтное местоположение
     */
    private function getDefaultLocation(string $ip): array
    {
        return [
            'ip' => $ip,
            'country' => 'Unknown',
            'iso_code' => 'Unknown',
            'city' => 'Unknown',
            'state' => null,
            'state_name' => null,
            'postal_code' => null,
            'lat' => 0,
            'lon' => 0,
            'timezone' => 'UTC',
            'continent' => 'Unknown',
            'currency' => 'USD',
            'source' => 'default'
        ];
    }

    /**
     * Получить IP адрес клиента
     */
    public function getClientIp(): string
    {
        try {
            // Сначала пробуем стандартный Laravel метод
            $laravelIp = request()->ip();
            
            // Если не localhost - возвращаем
            if ($laravelIp && $laravelIp !== '127.0.0.1' && $laravelIp !== '::1' && $laravelIp !== 'localhost') {
                return $laravelIp;
            }
            
            // Приоритетный список заголовков для определения IP
            $ipHeaders = [
                'HTTP_CF_CONNECTING_IP',      // Cloudflare
                'HTTP_X_FORWARDED_FOR',       // Стандартный прокси
                'HTTP_X_REAL_IP',             // Nginx
                'HTTP_CLIENT_IP',              // Proxy
                'HTTP_X_FORWARDED',            // Proxy
                'HTTP_FORWARDED_FOR',          // Proxy
                'HTTP_FORWARDED',              // Proxy
                'REMOTE_ADDR'                  // Прямое соединение
            ];
            
            foreach ($ipHeaders as $header) {
                if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                    $ipValue = $_SERVER[$header];
                    
                    // Если несколько IP через запятую, берем первый
                    if (strpos($ipValue, ',') !== false) {
                        $ips = explode(',', $ipValue);
                        $ipValue = trim($ips[0]);
                    }
                    
                    // Валидация IP
                    if (filter_var($ipValue, FILTER_VALIDATE_IP)) {
                        // Если это localhost, логируем для отладки
                        if ($ipValue === '127.0.0.1' || $ipValue === '::1' || $ipValue === 'localhost') {
                            Log::warning("GeoIP: Detected localhost IP - {$header}: {$ipValue}");
                        }
                        
                        return $ipValue;
                    }
                }
            }
            
            // Последний fallback - берем из $_SERVER напрямую
            $directIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            Log::warning("GeoIP: Using fallback IP", ['ip' => $directIp]);
            
            return $directIp;
        } catch (\Exception $e) {
            Log::error('GeoIP: Error getting client IP', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        }
    }

    /**
     * Проверить, является ли запрос от бота
     * Для ботов не делаем запросы к DaData, чтобы экономить лимит
     * 
     * @return bool
     */
    private function isBotRequest(): bool
    {
        try {
            $userAgent = request()->userAgent() ?? '';
            $userAgentLower = strtolower($userAgent);
            
            // Разрешенные боты (Google, Yandex, Bing) - для них тоже не делаем запросы к DaData
            $allowedBots = ['googlebot', 'yandex', 'bingbot', 'slurp', 'duckduckbot'];
            foreach ($allowedBots as $bot) {
                if (strpos($userAgentLower, $bot) !== false) {
                    return true; // Это разрешенный бот, но все равно не делаем запрос к DaData
                }
            }
            
            // Признаки ботов
            $botSignals = [
                'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 
                'python', 'java', 'go-http', 'http', 'scrapy', 
                'headless', 'phantom', 'selenium', 'facebookexternalhit',
                'twitterbot', 'linkedinbot', 'applebot'
            ];
            
            foreach ($botSignals as $signal) {
                if (strpos($userAgentLower, $signal) !== false) {
                    return true;
                }
            }
            
            // Отсутствие User-Agent
            if (empty($userAgent)) {
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            // В случае ошибки считаем, что это не бот (безопаснее)
            Log::debug('GeoIP: Error checking bot status', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Поиск организации по ИНН через DaData API
     * Документация: https://dadata.ru/api/find-party/
     * 
     * @param string $inn ИНН или ОГРН
     * @param string|null $type Тип организации: 'LEGAL' (юрлицо) или 'INDIVIDUAL' (ИП)
     * @return array
     */
    public function findPartyByInn(string $inn, ?string $type = null): array
    {
        try {
            if (!$this->dadataClient || !$this->dadataApiKey || !$this->dadataSecretKey) {
                return [
                    'success' => false,
                    'error' => 'DaData API ключи не настроены в .env (DADATA_API_KEY, DADATA_SECRET_KEY)',
                    'data' => null
                ];
            }

            Log::debug('DaData: Поиск организации по ИНН', [
                'inn' => $inn,
                'type' => $type
            ]);

            // Прямой HTTP запрос к DaData API для поиска организации
            // POST https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party
            $url = $this->dadataApiUrl . '/findById/party';

            $requestData = [
                'query' => $inn,
                'count' => 1
            ];

            // Добавляем фильтр по типу, если указан
            if ($type) {
                $requestData['type'] = $type; // 'LEGAL' или 'INDIVIDUAL'
            }

            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Token ' . $this->dadataApiKey
                ])
                ->post($url, $requestData);

            if (!$response->successful()) {
                Log::error('DaData findParty: API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'inn' => $inn
                ]);
                return [
                    'success' => false,
                    'error' => 'Ошибка запроса к DaData API: HTTP ' . $response->status(),
                    'data' => null
                ];
            }

            $result = $response->json();
            $suggestions = $result['suggestions'] ?? [];

            if (empty($suggestions)) {
                Log::debug('DaData findParty: Организация не найдена', ['inn' => $inn]);
                return [
                    'success' => false,
                    'error' => 'Организация с указанным ИНН не найдена',
                    'data' => null
                ];
            }

            $suggestion = $suggestions[0];
            $data = $suggestion['data'] ?? [];

            Log::debug('DaData findParty: Raw data structure', [
                'has_data' => !empty($data),
                'data_keys' => array_keys($data),
                'type' => $data['type'] ?? 'unknown',
                'has_name' => isset($data['name']),
                'has_fio' => isset($data['fio']),
                'has_address' => isset($data['address']),
            ]);

            // Формируем структурированный ответ
            $partyData = [
                'inn' => $data['inn'] ?? null,
                'kpp' => $data['kpp'] ?? null,
                'ogrn' => $data['ogrn'] ?? null,
                'ogrn_date' => isset($data['ogrn_date']) ? date('Y-m-d', $data['ogrn_date'] / 1000) : null,
                'name' => $data['name']['full_with_opf'] ?? $data['name']['full'] ?? $data['name']['short_with_opf'] ?? $data['name']['short'] ?? null,
                'name_full' => $data['name']['full_with_opf'] ?? $data['name']['full'] ?? null,
                'name_short' => $data['name']['short_with_opf'] ?? $data['name']['short'] ?? null,
                'type' => $data['type'] ?? null, // 'LEGAL' или 'INDIVIDUAL'
                'status' => $data['state']['status'] ?? null,
                'registration_date' => isset($data['state']['registration_date']) ? date('Y-m-d', $data['state']['registration_date'] / 1000) : null,
                'liquidation_date' => isset($data['state']['liquidation_date']) ? date('Y-m-d', $data['state']['liquidation_date'] / 1000) : null,
                'address' => [
                    'value' => $data['address']['value'] ?? null,
                    'unrestricted_value' => $data['address']['unrestricted_value'] ?? null,
                    'postal_code' => $data['address']['data']['postal_code'] ?? null,
                    'region' => $data['address']['data']['region_with_type'] ?? null,
                    'city' => $data['address']['data']['city_with_type'] ?? null,
                    'street' => $data['address']['data']['street_with_type'] ?? null,
                    'house' => $data['address']['data']['house'] ?? null,
                ],
                'management' => [
                    'name' => $data['management']['name'] ?? null,
                    'post' => $data['management']['post'] ?? null,
                ],
                'opf' => [
                    'code' => $data['opf']['code'] ?? null,
                    'full' => $data['opf']['full'] ?? null,
                    'short' => $data['opf']['short'] ?? null,
                ],
                // Для ИП - формируем ФИО из объекта
                'fio' => $data['fio'] ?? null, // Объект с surname, name, patronymic
                'fio_full' => null, // Полное ФИО одной строкой
                // Банковские реквизиты (если есть)
                'bank' => [
                    'name' => $data['bank']['name'] ?? null,
                    'bik' => $data['bank']['bik'] ?? null,
                    'account' => $data['bank']['account'] ?? null,
                ],
            ];

            // Формируем полное ФИО для ИП из объекта fio
            if ($partyData['type'] === 'INDIVIDUAL' && isset($data['fio']) && is_array($data['fio'])) {
                $fioParts = array_filter([
                    $data['fio']['surname'] ?? null,
                    $data['fio']['name'] ?? null,
                    $data['fio']['patronymic'] ?? null,
                ]);
                $partyData['fio_full'] = !empty($fioParts) ? implode(' ', $fioParts) : null;
            }

            Log::info('DaData findParty: Организация найдена', [
                'inn' => $inn,
                'name' => $partyData['name_full'] ?? $partyData['name'],
                'type' => $partyData['type']
            ]);

            return [
                'success' => true,
                'data' => $partyData,
                'error' => null
            ];

        } catch (\Exception $e) {
            Log::error('GeoLocationService: findPartyByInn error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'inn' => $inn
            ]);

            return [
                'success' => false,
                'error' => 'Ошибка при поиске организации: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}
