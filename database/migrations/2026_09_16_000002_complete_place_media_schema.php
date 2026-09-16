<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('place_images')) {
            if (!Schema::hasColumn('place_images', 'thumbnail_url')) {
                Schema::table('place_images', function (Blueprint $table) {
                    $table->string('thumbnail_url')->nullable()->after('url');
                });
            }
            if (!Schema::hasColumn('place_images', 'width')) {
                Schema::table('place_images', function (Blueprint $table) {
                    $table->unsignedInteger('width')->nullable()->after('thumbnail_url');
                });
            }
            if (!Schema::hasColumn('place_images', 'height')) {
                Schema::table('place_images', function (Blueprint $table) {
                    $table->unsignedInteger('height')->nullable()->after('width');
                });
            }
            if (!Schema::hasColumn('place_images', 'file_size')) {
                Schema::table('place_images', function (Blueprint $table) {
                    $table->unsignedBigInteger('file_size')->nullable()->after('height');
                });
            }
            if (!Schema::hasColumn('place_images', 'mime_type')) {
                Schema::table('place_images', function (Blueprint $table) {
                    $table->string('mime_type', 128)->nullable()->after('file_size');
                });
            }
        }

        if (!Schema::hasTable('place_videos')) {
            Schema::create('place_videos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
                $table->string('url');
                $table->string('preview_url')->nullable();
                $table->string('poster_url')->nullable();
                $table->string('thumbnail_url')->nullable();
                $table->decimal('duration', 8, 2)->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->string('mime_type', 128)->nullable();
                $table->timestamps();
                $table->index(['place_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // Compatibility migration: existing installations can already own
        // some of these columns or the table, so rollback must not remove them.
    }
};
