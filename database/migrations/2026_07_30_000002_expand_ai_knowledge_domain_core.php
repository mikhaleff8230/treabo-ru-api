<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_knowledge_versions', function (Blueprint $table) {
            $table->foreignId('rollback_of_version_id')->nullable()->after('based_on_version_id')
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->string('index_checksum', 64)->nullable()->after('documents_checksum');
            $table->json('publication_report')->nullable()->after('metrics');
        });

        Schema::create('ai_knowledge_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_version_id')->constrained('ai_knowledge_versions')->cascadeOnDelete();
            $table->string('stable_key', 160);
            $table->string('display_text', 255);
            $table->string('normalized_text', 255)->index();
            $table->enum('term_type', [
                'service', 'action', 'object', 'problem', 'material', 'parameter',
                'brand', 'informational', 'negative', 'unknown',
            ])->default('unknown')->index();
            $table->string('language', 8)->default('ru');
            $table->string('region', 128)->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->index();
            $table->unsignedBigInteger('frequency')->default(0);
            $table->unsignedBigInteger('use_count')->default(0);
            $table->foreignId('created_from_proposal_id')->nullable()
                ->constrained('ai_knowledge_proposals')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_version_id', 'stable_key'], 'ai_terms_version_key_unique');
            $table->index(['knowledge_version_id', 'status'], 'ai_terms_version_status_idx');
        });

        Schema::create('ai_knowledge_term_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('term_id')->constrained('ai_knowledge_terms')->cascadeOnDelete();
            $table->string('variant_text', 500);
            $table->string('normalized_text', 500)->index();
            $table->enum('variant_type', [
                'synonym', 'misspelling', 'wordform', 'translit',
                'professional', 'colloquial', 'search_phrase',
            ])->default('search_phrase')->index();
            $table->unsignedBigInteger('frequency')->default(0);
            $table->decimal('confidence', 5, 4)->default(0);
            $table->foreignId('source_row_id')->nullable()
                ->constrained('ai_knowledge_source_rows')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->unique(['term_id', 'normalized_text'], 'ai_term_variants_unique');
        });

        Schema::create('ai_knowledge_term_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_version_id')->constrained('ai_knowledge_versions')->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('ai_knowledge_terms')->cascadeOnDelete();
            $table->enum('target_type', [
                'category', 'service', 'question', 'option', 'material', 'safety_rule',
            ])->index();
            $table->string('target_id', 128)->index();
            $table->enum('relation', [
                'alias_of', 'indicates', 'excludes', 'requires_context',
                'part_of', 'problem_of', 'material_for',
            ])->default('indicates')->index();
            $table->decimal('weight', 5, 4)->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->index();
            $table->foreignId('created_from_proposal_id')->nullable()
                ->constrained('ai_knowledge_proposals')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['knowledge_version_id', 'term_id', 'target_type', 'target_id', 'relation'],
                'ai_term_links_unique'
            );
        });

        Schema::create('ai_knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_version_id')->constrained('ai_knowledge_versions')->cascadeOnDelete();
            $table->enum('document_type', [
                'catalog_entity', 'term_cluster', 'training_example', 'instruction', 'expert_note',
            ])->index();
            $table->string('entity_type', 64)->nullable()->index();
            $table->string('entity_id', 128)->nullable()->index();
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['knowledge_version_id', 'content_hash'], 'ai_documents_version_hash_unique');
        });

        Schema::create('ai_knowledge_proposal_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('ai_knowledge_proposals')->cascadeOnDelete();
            $table->text('question');
            $table->enum('answer_type', ['text', 'boolean', 'single', 'multiple'])->default('text');
            $table->json('options')->nullable();
            $table->json('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_training_examples', function (Blueprint $table) {
            $table->id();
            $table->longText('input_text_redacted');
            $table->json('expected');
            $table->enum('label_source', [
                'admin', 'customer_correction', 'master_correction', 'accepted_proposal', 'synthetic',
            ])->index();
            $table->enum('quality', ['gold', 'silver', 'bronze', 'quarantine'])->default('silver')->index();
            $table->decimal('weight', 5, 3)->default(1);
            $table->foreignId('knowledge_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->enum('split', ['train', 'validation', 'test', 'quarantine'])->default('train')->index();
            $table->string('content_hash', 64)->unique();
            $table->boolean('consent_confirmed')->default(false);
            $table->timestamp('retain_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_version_id')->constrained('ai_knowledge_versions')->cascadeOnDelete();
            $table->foreignId('baseline_version_id')->nullable()
                ->constrained('ai_knowledge_versions')->nullOnDelete();
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->enum('status', ['queued', 'running', 'passed', 'failed', 'cancelled'])->index();
            $table->json('metrics_before')->nullable();
            $table->json('metrics_after')->nullable();
            $table->json('failures')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_runs');
        Schema::dropIfExists('ai_training_examples');
        Schema::dropIfExists('ai_knowledge_proposal_questions');
        Schema::dropIfExists('ai_knowledge_documents');
        Schema::dropIfExists('ai_knowledge_term_links');
        Schema::dropIfExists('ai_knowledge_term_variants');
        Schema::dropIfExists('ai_knowledge_terms');

        Schema::table('ai_knowledge_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rollback_of_version_id');
            $table->dropColumn(['index_checksum', 'publication_report']);
        });
    }
};
