<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64)->unique();
            $table->string('purpose', 64)->index();
            $table->enum('status', ['draft', 'testing', 'published', 'archived'])->index();
            $table->string('model', 128);
            $table->longText('instructions');
            $table->json('schema')->nullable();
            $table->json('settings')->nullable();
            $table->string('checksum', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('request_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token_hash', 64)->nullable()->index();
            $table->ulid('parent_draft_id')->nullable()->index();
            $table->foreignId('task_id')->nullable()->constrained('proffi_tasks')->nullOnDelete();
            $table->uuid('client_draft_id')->nullable()->index();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->enum('status', [
                'new', 'classifying', 'clarifying', 'ready_for_review', 'awaiting_contact',
                'publishing', 'published', 'manual_selection', 'split_intents', 'paused',
                'expired', 'failed_recoverable', 'abandoned',
            ])->default('new')->index();
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('catalog_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->foreignId('prompt_version_id')->nullable()
                ->constrained('ai_prompt_versions')->nullOnDelete();
            $table->string('selected_category_id', 64)->nullable()->index();
            $table->foreignId('selected_service_id')->nullable()
                ->constrained('proffi_works')->nullOnDelete();
            $table->string('input_class', 32)->nullable()->index();
            $table->json('snapshot');
            $table->unsignedTinyInteger('ai_calls_count')->default(0);
            $table->unsignedTinyInteger('questions_asked_count')->default(0);
            $table->unsignedTinyInteger('meaningless_turns_count')->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->string('last_openai_response_id', 128)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_activity_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'status', 'updated_at']);
        });

        Schema::create('request_draft_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('draft_id');
            $table->unsignedInteger('turn_no');
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content')->nullable();
            $table->foreignId('question_id')->nullable()
                ->constrained('proffi_work_questions')->nullOnDelete();
            $table->uuid('client_turn_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('draft_id')->references('id')->on('request_drafts')->cascadeOnDelete();
            $table->unique(['draft_id', 'client_turn_id']);
            $table->unique(['draft_id', 'turn_no', 'role'], 'request_draft_turn_role_unique');
        });

        Schema::create('request_draft_answers', function (Blueprint $table) {
            $table->id();
            $table->ulid('draft_id');
            $table->foreignId('question_id')->constrained('proffi_work_questions')->cascadeOnDelete();
            $table->json('value');
            $table->string('display_value', 500)->nullable();
            $table->enum('source', [
                'user_explicit', 'user_selected', 'ai_extracted', 'system_default', 'admin_rule',
            ])->index();
            $table->decimal('confidence', 5, 4)->default(1);
            $table->foreignId('evidence_message_id')->nullable()
                ->constrained('request_draft_messages')->nullOnDelete();
            $table->boolean('is_confirmed')->default(false);
            $table->foreignId('catalog_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->timestamps();

            $table->foreign('draft_id')->references('id')->on('request_drafts')->cascadeOnDelete();
            $table->unique(['draft_id', 'question_id']);
        });

        Schema::create('request_draft_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('draft_id');
            $table->string('event_type', 64)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('draft_id')->references('id')->on('request_drafts')->cascadeOnDelete();
            $table->index(['draft_id', 'created_at']);
        });

        Schema::create('ai_invocations', function (Blueprint $table) {
            $table->id();
            $table->ulid('draft_id')->nullable();
            $table->foreignId('message_id')->nullable()
                ->constrained('request_draft_messages')->nullOnDelete();
            $table->string('request_id', 64)->index();
            $table->string('provider', 32)->default('openai');
            $table->string('model', 128);
            $table->string('endpoint', 64)->default('responses');
            $table->foreignId('prompt_version_id')->nullable()
                ->constrained('ai_prompt_versions')->nullOnDelete();
            $table->foreignId('catalog_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->string('response_id', 128)->nullable();
            $table->enum('status', ['started', 'succeeded', 'failed', 'rejected'])->index();
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedTinyInteger('retries')->default(0);
            $table->string('schema_name', 128);
            $table->string('schema_version', 32);
            $table->string('request_hash', 64)->nullable();
            $table->string('response_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('draft_id')->references('id')->on('request_drafts')->nullOnDelete();
            $table->index(['draft_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_invocations');
        Schema::dropIfExists('request_draft_events');
        Schema::dropIfExists('request_draft_answers');
        Schema::dropIfExists('request_draft_messages');
        Schema::dropIfExists('request_drafts');
        Schema::dropIfExists('ai_prompt_versions');
    }
};
