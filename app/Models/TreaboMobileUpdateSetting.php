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

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'latest_version' => '1.0.0',
                'latest_build' => 2,
                'min_supported_build' => 1,
                'force_update' => false,
                'android_url' => 'https://treabo.ru/downloads/treabo-proffi.apk',
                'ios_url' => null,
                'release_notes' => null,
                'is_active' => true,
            ]
        );
    }
}
