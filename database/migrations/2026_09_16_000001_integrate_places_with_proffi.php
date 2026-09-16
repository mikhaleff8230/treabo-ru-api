<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('category_id', 64)->nullable()->after('user_id');
            $table->foreignId('work_id')->nullable()->after('category_id');
            $table->unsignedBigInteger('price')->nullable()->after('description');
            $table->boolean('hide_price')->default(false)->after('price');
            $table->string('city', 128)->nullable()->after('hide_price');
            $table->foreignId('location_id')->nullable()->after('city');
            $table->decimal('lat', 10, 7)->nullable()->after('location_id');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->unsignedSmallInteger('duration_days')->nullable()->after('lng');
            $table->string('status', 32)->default('published')->after('duration_days');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->foreignId('source_task_id')->nullable()->after('published_at');

            $table->foreign('category_id')->references('id')->on('proffi_categories')->nullOnDelete();
            $table->foreign('work_id')->references('id')->on('proffi_works')->nullOnDelete();
            $table->foreign('location_id')->references('id')->on('russia_locations')->nullOnDelete();
            $table->foreign('source_task_id')->references('id')->on('proffi_tasks')->nullOnDelete();
            $table->index(['status', 'published_at']);
            $table->index(['category_id', 'work_id', 'status'], 'places_catalog_status_index');
            $table->index(['lat', 'lng', 'status'], 'places_geo_status_index');
            $table->index(['user_id', 'status']);
        });

        DB::table('places')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('created_at')]);

        Schema::table('place_images', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('url');
            $table->boolean('is_cover')->default(false)->after('sort_order');
            $table->index(['place_id', 'sort_order']);
        });

        Schema::table('request_drafts', function (Blueprint $table) {
            $table->foreignId('source_place_id')->nullable()->after('task_id')
                ->constrained('places')->nullOnDelete();
            $table->index(['source_place_id', 'created_at']);
        });

        Schema::table('proffi_tasks', function (Blueprint $table) {
            $table->foreignId('source_place_id')->nullable()->after('accepted_specialist_id')
                ->constrained('places')->nullOnDelete();
            $table->index(['source_place_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('proffi_tasks', function (Blueprint $table) {
            $table->dropIndex(['source_place_id', 'created_at']);
            $table->dropConstrainedForeignId('source_place_id');
        });

        Schema::table('request_drafts', function (Blueprint $table) {
            $table->dropIndex(['source_place_id', 'created_at']);
            $table->dropConstrainedForeignId('source_place_id');
        });

        Schema::table('place_images', function (Blueprint $table) {
            $table->dropIndex(['place_id', 'sort_order']);
            $table->dropColumn(['sort_order', 'is_cover']);
        });

        Schema::table('places', function (Blueprint $table) {
            $table->dropIndex(['status', 'published_at']);
            $table->dropIndex('places_catalog_status_index');
            $table->dropIndex('places_geo_status_index');
            $table->dropIndex(['user_id', 'status']);
            $table->dropForeign(['category_id']);
            $table->dropForeign(['work_id']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['source_task_id']);
            $table->dropColumn([
                'category_id', 'work_id', 'price', 'hide_price', 'city', 'location_id',
                'lat', 'lng', 'duration_days', 'status', 'published_at', 'source_task_id',
            ]);
        });
    }
};
