<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_tasks', 'work_id')) {
                $table->foreignId('work_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('proffi_works')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('proffi_tasks', 'ai_details')) {
                $table->json('ai_details')->nullable()->after('photos');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proffi_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('proffi_tasks', 'work_id')) {
                $table->dropConstrainedForeignId('work_id');
            }

            if (Schema::hasColumn('proffi_tasks', 'ai_details')) {
                $table->dropColumn('ai_details');
            }
        });
    }
};
