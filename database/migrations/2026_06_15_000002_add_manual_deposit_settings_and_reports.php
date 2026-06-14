<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treabo_response_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('treabo_response_settings', 'manual_deposit_amount_mdl')) {
                $table->unsignedInteger('manual_deposit_amount_mdl')->default(100)->after('default_response_price_mdl');
            }
            if (!Schema::hasColumn('treabo_response_settings', 'manual_deposit_url')) {
                $table->text('manual_deposit_url')->nullable()->after('manual_deposit_amount_mdl');
            }
        });

        Schema::table('balance_deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('balance_deposits', 'reported_at')) {
                $table->timestamp('reported_at')->nullable()->after('paid_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('balance_deposits', function (Blueprint $table) {
            if (Schema::hasColumn('balance_deposits', 'reported_at')) {
                $table->dropColumn('reported_at');
            }
        });

        Schema::table('treabo_response_settings', function (Blueprint $table) {
            if (Schema::hasColumn('treabo_response_settings', 'manual_deposit_url')) {
                $table->dropColumn('manual_deposit_url');
            }
            if (Schema::hasColumn('treabo_response_settings', 'manual_deposit_amount_mdl')) {
                $table->dropColumn('manual_deposit_amount_mdl');
            }
        });
    }
};
