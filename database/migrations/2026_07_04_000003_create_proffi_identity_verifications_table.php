<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proffi_identity_verifications')) {
            return;
        }

        Schema::create('proffi_identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('not_submitted');
            $table->string('passport_main_photo')->nullable();
            $table->string('passport_registration_photo')->nullable();
            $table->string('passport_selfie_photo')->nullable();
            $table->text('moderator_comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_identity_verifications');
    }
};
