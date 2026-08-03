<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TreaboMobileUpdateSetting extends Model
{
    protected $table = 'treabo_mobile_update_settings';
    protected $guarded = [];
    protected $casts = [
        'latest_build' => 'integer',
        'min_supported_build' => 'integer',
        'force_update' => 'boolean',
        'is_active' => 'boolean',
    ];

    public static function current(string $appType = 'specialist'): self
    {
        $appType = $appType === 'client' ? 'client' : 'specialist';

        return self::query()->firstOrCreate(
            ['app_type' => $appType],
            [
                'latest_version' => '1.0.0',
                'latest_build' => $appType === 'specialist' ? 11 : 1,
                'min_supported_build' => 1,
                'force_update' => false,
                'android_url' => $appType === 'specialist'
                    ? 'https://treabo.ru/downloads/treabo-proffi.apk'
                    : 'https://treabo.ru/downloads/treabo-client.apk',
                'ios_url' => null,
                'release_notes' => null,
                'is_active' => true,
            ]
        );
    }
}
