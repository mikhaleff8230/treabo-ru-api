<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiApplication;
use App\Models\ProffiChat;
use App\Models\ProffiMessage;
use App\Models\ProffiTask;
use App\Models\SellerBalance;
use App\Models\TreaboResponseSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function preview(Request $request, ProffiTask $task)
    {
        if ((int) $task->customer_id === (int) $request->user()->id) {
            return response()->json(['detail' => 'You cannot apply to your own task'], 400);
        }

        if ($task->status !== 'open') {
            return response()->json(['detail' => 'Task is not open'], 400);
        }

        return response()->json($this->responsePreview($request, $task));
    }

    public function store(Request $request, ProffiTask $task)
    {
        if ((int) $task->customer_id === (int) $request->user()->id) {
            return response()->json(['detail' => 'You cannot apply to your own task'], 400);
        }
        if ($task->status !== 'open') {
            return response()->json(['detail' => 'Task is not open'], 400);
        }

        $data = $request->validate([
            'message' => ['required', 'string'],
            'price' => ['nullable', 'integer', 'min:0'],
        ]);

        $application = DB::transaction(function () use ($request, $task, $data) {
            $existing = ProffiApplication::where('task_id', $task->id)
                ->where('specialist_id', $request->user()->id)
                ->first();

            if ($existing) {
                $existing->update([
                    'message' => $data['message'],
                    'price' => $data['price'] ?? null,
                    'status' => 'pending',
                ]);

                return $existing;
            }

            $preview = $this->responsePreview($request, $task);
            $fee = (int) $preview['response_fee_mdl'];

            if ($preview['charge_required'] && $fee > 0) {
                $balance = SellerBalance::getOrCreate((int) $request->user()->id);
                if (!$balance->hasEnough($fee)) {
                    abort(response()->json([
                        'detail' => 'Недостаточно средств на балансе для платного отклика',
                        'requires_payment' => true,
                        'response_fee_mdl' => $fee,
                        'balance' => (float) $balance->balance,
                    ], 402));
                }

                $balance->withdraw($fee);
            }

            return ProffiApplication::create([
                'task_id' => $task->id,
                'specialist_id' => $request->user()->id,
                'message' => $data['message'],
                'price' => $data['price'] ?? null,
                'response_fee_mdl' => $fee,
                'status' => 'pending',
            ]);
        });

        $chat = ProffiChat::updateOrCreate(
            ['task_id' => $task->id, 'specialist_id' => $request->user()->id],
            [
                'application_id' => $application->id,
                'customer_id' => $task->customer_id,
                'last_message' => $data['message'],
                'last_message_at' => now(),
            ]
        );
        ProffiMessage::firstOrCreate(
            [
                'chat_id' => $chat->id,
                'sender_id' => $request->user()->id,
                'text' => $data['message'],
            ]
        );

        return response()->json($this->mapApplication($application->load('task'), $chat), 201);
    }

    private function responsePreview(Request $request, ProffiTask $task): array
    {
        $settings = TreaboResponseSetting::current();
        $limit = max(0, (int) $settings->free_daily_limit);
        $price = (int) ($task->response_price_mdl ?: $settings->default_response_price_mdl ?: 15);
        $existing = ProffiApplication::where('task_id', $task->id)
            ->where('specialist_id', $request->user()->id)
            ->first();
        $usedToday = ProffiApplication::where('specialist_id', $request->user()->id)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();
        $remainingBefore = max(0, $limit - $usedToday);
        $isFree = !$existing && $remainingBefore > 0;

        return [
            'has_applied' => (bool) $existing,
            'free_daily_limit' => $limit,
            'free_used_today' => $usedToday,
            'free_remaining_before' => $remainingBefore,
            'free_remaining_after' => $existing ? $remainingBefore : max(0, $remainingBefore - 1),
            'charge_required' => !$existing && !$isFree,
            'is_free' => $isFree,
            'response_fee_mdl' => $existing ? (int) ($existing->response_fee_mdl ?? 0) : ($isFree ? 0 : $price),
            'default_response_price_mdl' => (int) $settings->default_response_price_mdl,
            'currency' => 'RUB',
        ];
    }

    public function mine(Request $request)
    {
        return ProffiApplication::with('task')
            ->where('specialist_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (ProffiApplication $application) => $this->mapApplication($application))
            ->values();
    }

    public function accept(Request $request, ProffiApplication $application)
    {
        $application->load('task');
        $task = $application->task;
        if (!$task || (int) $task->customer_id !== (int) $request->user()->id) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        ProffiApplication::where('task_id', $task->id)->where('id', '!=', $application->id)->update(['status' => 'rejected']);
        $application->update(['status' => 'accepted']);
        $task->update(['status' => 'in_progress', 'accepted_specialist_id' => $application->specialist_id]);

        $chat = ProffiChat::updateOrCreate(
            ['task_id' => $task->id, 'specialist_id' => $application->specialist_id],
            ['application_id' => $application->id, 'customer_id' => $task->customer_id]
        );

        return ['ok' => true, 'chat_id' => (string) $chat->id];
    }

    private function mapApplication(ProffiApplication $application, ?ProffiChat $chat = null): array
    {
        $chat ??= ProffiChat::where('application_id', $application->id)->first();
        return [
            'id' => (string) $application->id,
            'task_id' => (string) $application->task_id,
            'task_title' => $application->task?->title ?? '',
            'message' => $application->message,
            'price' => $application->price,
            'response_fee_mdl' => (int) ($application->response_fee_mdl ?? 15),
            'status' => $application->status,
            'chat_id' => $chat ? (string) $chat->id : null,
            'created_at' => optional($application->created_at)->toIso8601String(),
        ];
    }
}
