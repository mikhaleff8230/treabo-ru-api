<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiApplication;
use App\Models\ProffiChat;
use App\Models\ProffiMessage;
use App\Models\ProffiTask;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
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

        $application = ProffiApplication::updateOrCreate(
            ['task_id' => $task->id, 'specialist_id' => $request->user()->id],
            [
                'message' => $data['message'],
                'price' => $data['price'] ?? null,
                'response_fee_mdl' => (int) ($task->response_price_mdl ?? 15),
                'status' => 'pending',
            ]
        );

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
