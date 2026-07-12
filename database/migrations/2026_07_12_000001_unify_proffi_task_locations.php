<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('proffi_tasks', 'location_id')) {
            Schema::table('proffi_tasks', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->after('city')->constrained('russia_locations')->nullOnDelete();
            });
        }

        DB::table('proffi_tasks')->whereNull('location_id')->orderBy('id')->chunkById(200, function ($tasks) {
            foreach ($tasks as $task) {
                $location = DB::table('russia_locations')->where('is_active', true)
                    ->whereRaw('LOWER(name) = LOWER(?)', [$task->city])->whereNotNull('lat')->whereNotNull('lng')
                    ->orderByDesc('population')->first();
                if (!$location) continue;
                $patch = ['location_id' => $location->id, 'city' => $location->name];
                if ($task->lat === null) $patch['lat'] = $location->lat;
                if ($task->lng === null) $patch['lng'] = $location->lng;
                DB::table('proffi_tasks')->where('id', $task->id)->update($patch);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('proffi_tasks', 'location_id')) {
            Schema::table('proffi_tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('location_id'));
        }
    }
};
