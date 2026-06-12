<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateJobDraftRequest;
use App\Models\AiJobDraft;
use App\Services\Ai\JobDraftAiException;
use App\Services\Ai\JobDraftAiService;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class AiJobDraftController extends Controller
{
    public function generate(GenerateJobDraftRequest $request, JobDraftAiService $service)
    {
        $data = $request->validated();
        $data['language_hint'] = $data['language_hint'] ?? 'auto';
        $userId = $this->resolveUserId($request);

        try {
            $draft = $service->generateDraft($data);

            AiJobDraft::create([
                'user_id' => $userId,
                'raw_text' => $data['text'],
                'request_payload' => $data,
                'response_payload' => $draft,
                'model' => $service->model(),
                'status' => 'success',
                'tokens_used' => $service->tokensUsed(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $draft,
            ]);
        } catch (JobDraftAiException $e) {
            $this->logFailedDraft($data, $userId, $service->model(), $e);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось сформировать заявку',
            ], 422);
        } catch (\Throwable $e) {
            $this->logFailedDraft($data, $userId, $service->model(), $e);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось сформировать заявку',
            ], 422);
        }
    }

    private function logFailedDraft(array $data, ?int $userId, string $model, \Throwable $e): void
    {
        Log::error('AI job draft generation failed', [
            'message' => $e->getMessage(),
            'user_id' => $userId,
            'model' => $model,
        ]);

        try {
            AiJobDraft::create([
                'user_id' => $userId,
                'raw_text' => $data['text'] ?? '',
                'request_payload' => $data,
                'response_payload' => null,
                'model' => $model,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        } catch (\Throwable $logException) {
            Log::error('Failed to persist AI job draft error log', [
                'message' => $logException->getMessage(),
            ]);
        }
    }

    private function resolveUserId(GenerateJobDraftRequest $request): ?int
    {
        $user = $request->user();

        if (!$user && $request->bearerToken()) {
            try {
                $token = PersonalAccessToken::findToken($request->bearerToken());
                $user = $token?->tokenable;
            } catch (\Throwable) {
                $user = null;
            }
        }

        return $user?->id ? (int) $user->id : null;
    }
}
