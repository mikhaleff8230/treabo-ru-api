<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\RequestDraft;
use App\Services\AiAssistant\RequestDraftException;
use App\Services\AiAssistant\RequestDraftOrchestrator;
use App\Services\AiAssistant\RequestDraftPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RequestDraftController extends Controller
{
    public function store(Request $request, RequestDraftOrchestrator $orchestrator)
    {
        $data = $request->validate([
            'initial_text' => ['required', 'string', 'min:2', 'max:4000'],
            'city_hint' => ['nullable', 'string', 'max:128'],
            'photo_upload_ids' => ['nullable', 'array', 'max:10'],
            'photo_upload_ids.*' => ['string', 'max:255'],
            'client_draft_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        try {
            $result = $orchestrator->create($data, $this->userId($request));

            return response()->json($result, 201)
                ->cookie(
                    'treabo_draft_recovery',
                    $result['recovery_token'] ?? '',
                    60 * 24 * (int) config('ai_assistant.draft_ttl_days', 30),
                    '/',
                    null,
                    app()->environment('production'),
                    true,
                    false,
                    'Lax'
                );
        } catch (RequestDraftException $e) {
            return $this->error($e);
        }
    }

    public function show(Request $request, RequestDraft $draft, RequestDraftOrchestrator $orchestrator)
    {
        $this->authorizeDraft($request, $draft);

        return $orchestrator->response($draft);
    }

    public function latest(Request $request, RequestDraftOrchestrator $orchestrator)
    {
        $data = $request->validate(['client_draft_id' => ['required', 'uuid']]);
        $query = RequestDraft::where('client_draft_id', $data['client_draft_id'])->latest('updated_at');
        if ($userId = $this->userId($request)) {
            $query->where('user_id', $userId);
        } else {
            $token = (string) ($request->header('X-Draft-Recovery-Token')
                ?: $request->cookie('treabo_draft_recovery', ''));
            if ($token === '') {
                abort(404);
            }
            $query->where('guest_token_hash', hash('sha256', $token));
        }
        $draft = $query->firstOrFail();

        return $orchestrator->response($draft);
    }

    public function turn(
        Request $request,
        RequestDraft $draft,
        RequestDraftOrchestrator $orchestrator
    ) {
        $this->authorizeDraft($request, $draft);
        $data = $request->validate([
            'client_turn_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:4000', 'required_without_all:answer,skip_question_id'],
            'answer' => ['nullable', 'array', 'required_without_all:message,skip_question_id'],
            'answer.question_id' => ['required_with:answer', 'integer'],
            'answer.value' => ['required_with:answer'],
            'skip_question_id' => ['nullable', 'integer', 'required_without_all:message,answer'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            return $orchestrator->turn($draft, $data);
        } catch (RequestDraftException $e) {
            return $this->error($e);
        }
    }

    public function update(
        Request $request,
        RequestDraft $draft,
        RequestDraftOrchestrator $orchestrator
    ) {
        $this->authorizeDraft($request, $draft);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'changes' => ['required', 'array', 'min:1', 'max:20'],
            'changes.*.op' => ['required', 'in:replace'],
            'changes.*.path' => ['required', 'string'],
            'changes.*.value' => ['present'],
        ]);

        try {
            return $orchestrator->patch($draft, $data);
        } catch (RequestDraftException $e) {
            return $this->error($e);
        }
    }

    public function confirm(
        Request $request,
        RequestDraft $draft,
        RequestDraftPublisher $publisher,
        RequestDraftOrchestrator $orchestrator
    ) {
        if (!$request->user()) {
            return $this->error(new RequestDraftException(
                'AUTH_REQUIRED',
                'Для публикации заявки необходимо войти.',
                401
            ));
        }
        $this->authorizeDraft($request, $draft, true);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'consent' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        try {
            $task = $publisher->publish($draft, (int) $request->user()->id, (int) $data['expected_version']);

            return [
                'data' => [
                    'draft' => $orchestrator->response($draft->fresh())['data']['draft'],
                    'task_id' => (string) $task->id,
                    'task' => $task,
                ],
            ];
        } catch (RequestDraftException $e) {
            return $this->error($e);
        }
    }

    private function authorizeDraft(Request $request, RequestDraft $draft, bool $allowGuestClaim = false): void
    {
        $userId = $this->userId($request);
        if ($draft->user_id && (int) $draft->user_id === (int) $userId) {
            return;
        }
        $token = (string) ($request->header('X-Draft-Recovery-Token')
            ?: $request->cookie('treabo_draft_recovery', ''));
        if ($draft->guest_token_hash && $token !== ''
            && hash_equals($draft->guest_token_hash, hash('sha256', $token))
        ) {
            if ($allowGuestClaim && $userId) {
                $draft->update(['user_id' => $userId]);
            }

            return;
        }

        abort(404);
    }

    private function userId(Request $request): ?int
    {
        if ($request->user()) {
            return (int) $request->user()->id;
        }
        if (!$request->bearerToken()) {
            return null;
        }

        try {
            return \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken())?->tokenable?->id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function error(RequestDraftException $exception)
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
                'details' => $exception->details,
                'request_id' => 'req_'.Str::lower(Str::random(16)),
            ],
        ], $exception->httpStatus);
    }
}
