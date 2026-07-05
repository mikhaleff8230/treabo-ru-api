<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_reviews', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_reviews', 'photos')) {
                $table->json('photos')->nullable()->after('comment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proffi_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_reviews', 'photos')) {
                $table->dropColumn('photos');
            }
        });
    }
};
