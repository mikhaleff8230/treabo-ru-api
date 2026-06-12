<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_job_drafts')) {
            return;
        }

        Schema::create('ai_job_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('raw_text');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('model')->nullable();
            $table->enum('status', ['success', 'failed'])->index();
            $table->text('error_message')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_job_drafts');
    }
};
