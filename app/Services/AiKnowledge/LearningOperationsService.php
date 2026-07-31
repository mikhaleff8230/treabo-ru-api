<?php

namespace App\Services\AiKnowledge;

use App\Models\AiLearningEvent;
use App\Models\AiTrainingExample;
use Illuminate\Support\Facades\DB;

class LearningOperationsService
{
    public function promote(AiLearningEvent $event): AiTrainingExample
    {
        if ($event->status === 'processed') {
            $id = $event->after['training_example_id'] ?? null;
            if ($id && ($existing = AiTrainingExample::find($id))) {
                return $existing;
            }
        }
        if (!$event->redacted_evidence || !$event->after) {
            throw new \DomainException('Событие не содержит подтверждённого текста и целевого результата.');
        }

        return DB::transaction(function () use ($event) {
            $hash = hash('sha256', $event->redacted_evidence.'|'.json_encode($event->after));
            $example = AiTrainingExample::firstOrCreate(
                ['content_hash' => $hash],
                [
                    'input_text_redacted' => $event->redacted_evidence,
                    'expected' => $event->after,
                    'label_source' => $event->source_actor === 'master'
                        ? 'master_correction'
                        : 'customer_correction',
                    'quality' => $event->event_type === 'classification_corrected' ? 'gold' : 'silver',
                    'weight' => max(0.1, min(2, $event->weight)),
                    'knowledge_version_id' => null,
                    'split' => $this->split($hash),
                    'consent_confirmed' => false,
                    'retain_until' => now()->addYear(),
                    'metadata' => [
                        'learning_event_id' => $event->id,
                        'event_type' => $event->event_type,
                        'source_actor' => $event->source_actor,
                    ],
                ]
            );
            $event->update([
                'status' => 'processed',
                'after' => [...$event->after, 'training_example_id' => $example->id],
            ]);

            return $example;
        });
    }

    private function split(string $hash): string
    {
        $bucket = hexdec(substr($hash, 0, 2)) % 100;

        return $bucket < 10 ? 'test' : ($bucket < 20 ? 'validation' : 'train');
    }
}
