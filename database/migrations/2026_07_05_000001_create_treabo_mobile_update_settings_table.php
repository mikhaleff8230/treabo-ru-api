<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('treabo_mobile_update_settings')) {
            Schema::create('treabo_mobile_update_settings', function (Blueprint $table) {
                $table->id();
                $table->string('latest_version')->default('1.0.0');
                $table->unsignedInteger('latest_build')->default(1);
                $table->unsignedInteger('min_supported_build')->default(1);
                $table->boolean('force_update')->default(false);
                $table->string('android_url', 2048)->nullable();
                $table->string('ios_url', 2048)->nullable();
                $table->text('release_notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('treabo_mobile_update_settings');
    }
};
