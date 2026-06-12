<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_tasks', 'response_price_mdl')) {
                $table->unsignedInteger('response_price_mdl')->default(15)->after('budget');
            }
        });

        Schema::table('proffi_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_applications', 'response_fee_mdl')) {
                $table->unsignedInteger('response_fee_mdl')->default(15)->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proffi_applications', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_applications', 'response_fee_mdl')) {
                $table->dropColumn('response_fee_mdl');
            }
        });

        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_tasks', 'response_price_mdl')) {
                $table->dropColumn('response_price_mdl');
            }
        });
    }
};
