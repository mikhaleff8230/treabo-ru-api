<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('treabo_response_settings')) {
            Schema::create('treabo_response_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('free_daily_limit')->default(5);
                $table->unsignedInteger('default_response_price_mdl')->default(15);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('treabo_response_settings');
    }
};
