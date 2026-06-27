<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proffi_reviews')) {
            return;
        }

        Schema::create('proffi_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->nullable()->constrained('proffi_tasks')->nullOnDelete();
            $table->foreignId('specialist_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'customer_id', 'specialist_id'], 'proffi_reviews_unique_task_customer_specialist');
            $table->index(['specialist_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_reviews');
    }
};
