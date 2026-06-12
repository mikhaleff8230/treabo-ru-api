<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiChat;
use App\Models\ProffiMessage;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return ProffiChat::with(['task', 'customer', 'specialist'])
            ->where(fn ($query) => $query->where('customer_id', $userId)->orWhere('specialist_id', $userId))
            ->latest('updated_at')
            ->get()
            ->map(fn (ProffiChat $chat) => $this->mapChat($chat))
            ->values();
    }

    public function show(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        return $this->mapChat($chat->load(['task', 'customer', 'specialist']));
    }

    public function messages(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        return $chat->messages()
            ->oldest()
            ->get()
            ->map(fn (ProffiMessage $message) => $this->mapMessage($message))
            ->values();
    }

    public function send(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        $data = $request->validate(['text' => ['required', 'string']]);
        $message = $chat->messages()->create([
            'sender_id' => $request->user()->id,
            'text' => $data['text'],
        ]);
        $chat->update(['last_message' => $data['text'], 'last_message_at' => now()]);

        return response()->json($this->mapMessage($message), 201);
    }

    private function canAccess(Request $request, ProffiChat $chat): bool
    {
        $id = (int) $request->user()->id;
        return (int) $chat->customer_id === $id || (int) $chat->specialist_id === $id;
    }

    private function mapChat(ProffiChat $chat): array
    {
        return [
            'id' => (string) $chat->id,
            'task_id' => (string) $chat->task_id,
            'task_title' => $chat->task?->title,
            'customer_id' => (string) $chat->customer_id,
            'customer_name' => $chat->customer?->name,
            'specialist_id' => (string) $chat->specialist_id,
            'specialist_name' => $chat->specialist?->name,
            'last_message' => $chat->last_message,
            'last_message_at' => optional($chat->last_message_at)->toIso8601String(),
            'created_at' => optional($chat->created_at)->toIso8601String(),
            'updated_at' => optional($chat->updated_at)->toIso8601String(),
        ];
    }

    private function mapMessage(ProffiMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'chat_id' => (string) $message->chat_id,
            'sender_id' => (string) $message->sender_id,
            'user_id' => (string) $message->sender_id,
            'text' => $message->text,
            'type' => 'text',
            'created_at' => optional($message->created_at)->toIso8601String(),
        ];
    }
}
