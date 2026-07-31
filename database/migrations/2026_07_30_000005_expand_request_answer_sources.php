<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE request_draft_answers MODIFY source ENUM(
            'user_explicit','user_selected','user_skipped','user_corrected',
            'ai_extracted','system_default','admin_rule'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::table('request_draft_answers')
            ->whereIn('source', ['user_skipped', 'user_corrected'])
            ->update(['source' => 'user_selected']);
        DB::statement("ALTER TABLE request_draft_answers MODIFY source ENUM(
            'user_explicit','user_selected','ai_extracted','system_default','admin_rule'
        ) NOT NULL");
    }
};
