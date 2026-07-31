<?php

namespace App\Jobs\AiKnowledge;

use App\Models\AiKnowledgeImport;
use App\Services\AiKnowledge\KnowledgeLabAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeKnowledgeImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('knowledge-llm');
    }

    public function handle(KnowledgeLabAnalyzer $analyzer): void
    {
        $import = AiKnowledgeImport::findOrFail($this->importId);
        if (in_array($import->status, ['cancelled', 'completed'], true)) {
            return;
        }

        try {
            $analyzer->analyze($import);
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'failed',
                'error' => ['message' => $e->getMessage()],
                'finished_at' => now(),
            ]);
            Log::error('Knowledge Lab import failed', [
                'import_id' => $import->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
