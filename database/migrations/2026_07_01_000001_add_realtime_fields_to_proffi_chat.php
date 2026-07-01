<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proffi_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('text');
            }
            if (!Schema::hasColumn('proffi_messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('read_at');
            }
            if (!Schema::hasColumn('proffi_messages', 'type')) {
                $table->string('type', 32)->default('text')->after('delivered_at');
            }
            if (!Schema::hasColumn('proffi_messages', 'metadata')) {
                $table->json('metadata')->nullable()->after('type');
            }
        });

        Schema::table('proffi_chats', function (Blueprint $table) {
            if (!Schema::hasColumn('proffi_chats', 'customer_last_read_at')) {
                $table->timestamp('customer_last_read_at')->nullable()->after('last_message_at');
            }
            if (!Schema::hasColumn('proffi_chats', 'specialist_last_read_at')) {
                $table->timestamp('specialist_last_read_at')->nullable()->after('customer_last_read_at');
            }
            if (!Schema::hasColumn('proffi_chats', 'customer_typing_at')) {
                $table->timestamp('customer_typing_at')->nullable()->after('specialist_last_read_at');
            }
            if (!Schema::hasColumn('proffi_chats', 'specialist_typing_at')) {
                $table->timestamp('specialist_typing_at')->nullable()->after('customer_typing_at');
            }
        });

        if (!Schema::hasTable('proffi_user_presence')) {
            Schema::create('proffi_user_presence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->timestamp('last_seen_at')->nullable();
                $table->boolean('is_online')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proffi_user_presence');

        Schema::table('proffi_chats', function (Blueprint $table) {
            foreach (['customer_last_read_at', 'specialist_last_read_at', 'customer_typing_at', 'specialist_typing_at'] as $column) {
                if (Schema::hasColumn('proffi_chats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('proffi_messages', function (Blueprint $table) {
            foreach (['read_at', 'delivered_at', 'type', 'metadata'] as $column) {
                if (Schema::hasColumn('proffi_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
