<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_tasks', 'budget_type')) {
                $table->string('budget_type', 16)->default('fixed')->after('budget');
            }
            if (!Schema::hasColumn('proffi_tasks', 'budget_min')) {
                $table->unsignedInteger('budget_min')->nullable()->after('budget_type');
            }
            if (!Schema::hasColumn('proffi_tasks', 'budget_max')) {
                $table->unsignedInteger('budget_max')->nullable()->after('budget_min');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_tasks', 'budget_max')) {
                $table->dropColumn('budget_max');
            }
            if (Schema::hasColumn('proffi_tasks', 'budget_min')) {
                $table->dropColumn('budget_min');
            }
            if (Schema::hasColumn('proffi_tasks', 'budget_type')) {
                $table->dropColumn('budget_type');
            }
        });
    }
};
