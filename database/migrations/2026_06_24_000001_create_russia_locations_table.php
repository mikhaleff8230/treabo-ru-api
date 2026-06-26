<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('russia_locations')) {
            return;
        }

        Schema::create('russia_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('geoname_id')->unique();
            $table->string('name')->index();
            $table->string('ascii_name')->nullable()->index();
            $table->text('alternate_names')->nullable();
            $table->string('region')->nullable()->index();
            $table->string('admin1_code', 16)->nullable()->index();
            $table->string('feature_code', 16)->nullable()->index();
            $table->string('type', 32)->default('settlement')->index();
            $table->unsignedBigInteger('population')->default(0)->index();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('search_text')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->date('source_updated_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['is_active', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('russia_locations');
    }
};
