<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_categories', 'image')) {
                $table->string('image')->nullable()->after('icon');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proffi_categories', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_categories', 'image')) {
                $table->dropColumn('image');
            }
        });
    }
};
