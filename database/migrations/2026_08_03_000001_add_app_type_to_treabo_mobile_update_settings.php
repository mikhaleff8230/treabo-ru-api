<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('treabo_mobile_update_settings', 'app_type')) {
            Schema::table('treabo_mobile_update_settings', function (Blueprint $table) {
                $table->string('app_type', 32)->default('specialist')->after('id');
                $table->unique('app_type', 'treabo_mobile_update_app_type_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('treabo_mobile_update_settings', 'app_type')) {
            Schema::table('treabo_mobile_update_settings', function (Blueprint $table) {
                $table->dropUnique('treabo_mobile_update_app_type_unique');
                $table->dropColumn('app_type');
            });
        }
    }
};
