<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('user_profiles', 'proffi_city')) {
                $table->string('proffi_city')->nullable()->after('bio');
            }
            if (!Schema::hasColumn('user_profiles', 'proffi_services')) {
                $table->json('proffi_services')->nullable()->after('proffi_city');
            }
            if (!Schema::hasColumn('user_profiles', 'proffi_lat')) {
                $table->decimal('proffi_lat', 10, 7)->nullable()->after('proffi_services');
            }
            if (!Schema::hasColumn('user_profiles', 'proffi_lng')) {
                $table->decimal('proffi_lng', 10, 7)->nullable()->after('proffi_lat');
            }
        });

        if (!Schema::hasTable('proffi_categories')) {
            Schema::create('proffi_categories', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->string('icon', 64)->default('MoreHorizontal');
                $table->string('name_ru');
                $table->string('name_ro');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('proffi_tasks')) {
            Schema::create('proffi_tasks', function (Blueprint $table) {
                $table->id();
                $table->string('title', 512);
                $table->text('description');
                $table->string('category', 64)->index();
                $table->string('city', 128);
                $table->string('address', 512)->nullable();
                $table->unsignedInteger('budget')->nullable();
                $table->string('deadline', 64)->nullable();
                $table->string('status', 32)->default('open')->index();
                $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('accepted_specialist_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->json('photos')->nullable();
                $table->timestamps();
                $table->index(['customer_id', 'status']);
                $table->index(['accepted_specialist_id', 'status']);
            });
        }

        if (!Schema::hasTable('proffi_applications')) {
            Schema::create('proffi_applications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('proffi_tasks')->cascadeOnDelete();
                $table->foreignId('specialist_id')->constrained('users')->cascadeOnDelete();
                $table->text('message');
                $table->unsignedInteger('price')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->timestamps();
                $table->unique(['task_id', 'specialist_id']);
            });
        }

        if (!Schema::hasTable('proffi_chats')) {
            Schema::create('proffi_chats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('proffi_tasks')->cascadeOnDelete();
                $table->foreignId('application_id')->nullable()->constrained('proffi_applications')->nullOnDelete();
                $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('specialist_id')->constrained('users')->cascadeOnDelete();
                $table->text('last_message')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
                $table->unique(['task_id', 'specialist_id']);
                $table->index(['customer_id', 'updated_at']);
                $table->index(['specialist_id', 'updated_at']);
            });
        }

        if (!Schema::hasTable('proffi_messages')) {
            Schema::create('proffi_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_id')->constrained('proffi_chats')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('text');
                $table->timestamps();
                $table->index(['chat_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('proffi_filters')) {
            Schema::create('proffi_filters', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->string('name');
                $table->string('key', 128);
                $table->text('value');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_filters');
        Schema::dropIfExists('proffi_messages');
        Schema::dropIfExists('proffi_chats');
        Schema::dropIfExists('proffi_applications');
        Schema::dropIfExists('proffi_tasks');
        Schema::dropIfExists('proffi_categories');

        Schema::table('user_profiles', function (Blueprint $table) {
            foreach (['proffi_city', 'proffi_services', 'proffi_lat', 'proffi_lng'] as $column) {
                if (Schema::hasColumn('user_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
