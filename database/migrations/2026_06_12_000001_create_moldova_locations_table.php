<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('moldova_locations')) {
            return;
        }

        Schema::create('moldova_locations', function (Blueprint $table) {
            $table->id();
            $table->string('cuatm_code', 32)->nullable()->unique();
            $table->string('parent_cuatm_code', 32)->nullable()->index();
            $table->string('name_ro')->index();
            $table->string('name_ru')->nullable()->index();
            $table->string('name_en')->nullable();
            $table->string('ascii_name')->nullable()->index();
            $table->string('district_ro')->nullable()->index();
            $table->string('district_ru')->nullable();
            $table->string('region_ro')->nullable();
            $table->string('region_ru')->nullable();
            $table->string('type', 32)->index();
            $table->unsignedTinyInteger('level')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('aliases')->nullable();
            $table->text('search_text')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moldova_locations');
    }
};
