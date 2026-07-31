<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', [
                'manual_text', 'wordstat', 'csv', 'xlsx', 'requests', 'operator', 'master', 'external',
            ])->index();
            $table->text('description')->nullable();
            $table->string('default_region', 128)->nullable();
            $table->unsignedTinyInteger('trust_level')->default(50);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_knowledge_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64)->unique();
            $table->foreignId('based_on_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->enum('status', ['draft', 'testing', 'published', 'archived'])->index();
            $table->string('terms_checksum', 64)->nullable();
            $table->string('documents_checksum', 64)->nullable();
            $table->json('metrics')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_knowledge_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('ai_knowledge_sources')->cascadeOnDelete();
            $table->foreignId('knowledge_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->enum('status', [
                'uploaded', 'parsing', 'normalizing', 'queued', 'analyzing',
                'review', 'completed', 'failed', 'cancelled',
            ])->index();
            $table->enum('mode', ['terms', 'catalog', 'questions', 'full_analysis'])
                ->default('full_analysis');
            $table->string('category_hint', 128)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_unique')->default(0);
            $table->unsignedInteger('clusters_total')->default(0);
            $table->unsignedInteger('proposals_total')->default(0);
            $table->decimal('cost_limit_usd', 10, 6)->default(2);
            $table->decimal('actual_cost_usd', 10, 6)->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('ai_knowledge_source_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('ai_knowledge_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->text('raw_text');
            $table->text('normalized_text');
            $table->text('redacted_text');
            $table->unsignedBigInteger('frequency')->nullable();
            $table->string('region', 128)->nullable();
            $table->string('period', 32)->nullable();
            $table->string('language', 8)->default('ru');
            $table->string('content_hash', 64);
            $table->enum('status', ['ready', 'duplicate', 'ignored', 'processed', 'failed'])
                ->default('ready')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'content_hash']);
            $table->index(['import_id', 'status']);
        });

        Schema::create('ai_knowledge_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('ai_knowledge_imports')->cascadeOnDelete();
            $table->foreignId('knowledge_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->enum('proposal_type', [
                'add_alias', 'add_negative_alias', 'create_term', 'link_term',
                'create_category', 'create_service', 'create_question', 'create_option',
                'create_rule', 'merge_entities', 'mark_irrelevant',
            ])->index();
            $table->enum('status', [
                'generated', 'needs_clarification', 'in_review', 'accepted',
                'rejected', 'superseded', 'published',
            ])->default('generated')->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 128)->nullable();
            $table->string('title');
            $table->json('payload');
            $table->json('evidence');
            $table->decimal('confidence', 5, 4)->default(0);
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])
                ->default('medium')->index();
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->string('response_id', 128)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'status']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('ai_learning_events', function (Blueprint $table) {
            $table->id();
            $table->string('request_draft_id', 64)->nullable()->index();
            $table->foreignId('task_id')->nullable()->constrained('proffi_tasks')->nullOnDelete();
            $table->enum('event_type', [
                'classification_confirmed', 'classification_corrected', 'service_manually_selected',
                'answer_corrected', 'question_skipped', 'manual_fallback', 'master_reclassified',
                'task_completed', 'task_cancelled', 'unrecognized_text', 'multi_intent_split',
                'search_no_results',
            ])->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('redacted_evidence')->nullable();
            $table->string('source_actor', 32)->nullable();
            $table->decimal('weight', 4, 3)->default(0);
            $table->enum('status', ['new', 'processed', 'quarantined', 'ignored'])
                ->default('new')->index();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learning_events');
        Schema::dropIfExists('ai_knowledge_proposals');
        Schema::dropIfExists('ai_knowledge_source_rows');
        Schema::dropIfExists('ai_knowledge_imports');
        Schema::dropIfExists('ai_knowledge_versions');
        Schema::dropIfExists('ai_knowledge_sources');
    }
};
