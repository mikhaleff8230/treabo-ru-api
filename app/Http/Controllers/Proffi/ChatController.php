<?php

namespace App\Http\Controllers\Proffi;

use App\Events\Proffi\MessageSent;
use App\Events\Proffi\MessagesRead;
use App\Events\Proffi\UserPresenceUpdated;
use App\Events\Proffi\UserTyping;
use App\Http\Controllers\Controller;
use App\Mail\ProffiNewMessageMail;
use App\Models\ProffiChat;
use App\Models\ProffiMessage;
use App\Models\ProffiUserPresence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\User;

class ChatController extends Controller
{
    private const TYPING_TTL_SECONDS = 5;

    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;

        return ProffiChat::with(['task', 'customer', 'specialist'])
            ->where(fn ($query) => $query->where('customer_id', $userId)->orWhere('specialist_id', $userId))
            ->latest('updated_at')
            ->get()
            ->map(fn (ProffiChat $chat) => $this->mapChat($chat, $userId))
            ->values();
    }

    public function show(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        return $this->mapChat($chat->load(['task', 'customer', 'specialist']), (int) $request->user()->id);
    }

    public function messages(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        $userId = (int) $request->user()->id;
        $now = now();

        $chat->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $now]);

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
        $senderId = (int) $request->user()->id;
        $now = now();

        $message = $chat->messages()->create([
            'sender_id' => $senderId,
            'text' => $data['text'],
            'type' => 'text',
            'delivered_at' => $now,
        ]);

        $chat->update([
            'last_message' => $data['text'],
            'last_message_at' => $now,
        ]);

        $this->clearTyping($chat, $senderId);

        $chat->refresh();
        broadcast(new MessageSent($message, $chat));

        $this->notifyRecipientByEmail($chat, $request->user(), $data['text']);

        return response()->json($this->mapMessage($message), 201);
    }

    public function read(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        $userId = (int) $request->user()->id;
        $now = now();

        $chat->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        if ((int) $chat->customer_id === $userId) {
            $chat->update(['customer_last_read_at' => $now]);
        } else {
            $chat->update(['specialist_last_read_at' => $now]);
        }

        broadcast(new MessagesRead($chat->id, $userId, $now->toIso8601String()));

        return response()->json(['read_at' => $now->toIso8601String()]);
    }

    public function typing(Request $request, ProffiChat $chat)
    {
        if (!$this->canAccess($request, $chat)) {
            return response()->json(['detail' => 'Forbidden'], 403);
        }

        $data = $request->validate(['is_typing' => ['required', 'boolean']]);
        $userId = (int) $request->user()->id;
        $typingAt = $data['is_typing'] ? now() : null;

        if ((int) $chat->customer_id === $userId) {
            $chat->update(['customer_typing_at' => $typingAt]);
        } else {
            $chat->update(['specialist_typing_at' => $typingAt]);
        }

        $typingAtIso = $typingAt?->toIso8601String() ?? now()->toIso8601String();
        broadcast(new UserTyping($chat->id, $userId, $data['is_typing'], $typingAtIso));

        return response()->json(['is_typing' => $data['is_typing']]);
    }

    public function presenceHeartbeat(Request $request)
    {
        $userId = (int) $request->user()->id;
        $now = now();

        $presence = ProffiUserPresence::updateOrCreate(
            ['user_id' => $userId],
            ['last_seen_at' => $now, 'is_online' => true]
        );

        $chats = ProffiChat::where('customer_id', $userId)
            ->orWhere('specialist_id', $userId)
            ->get(['id']);

        foreach ($chats as $chat) {
            broadcast(new UserPresenceUpdated(
                $chat->id,
                $userId,
                true,
                $now->toIso8601String()
            ));
        }

        return response()->json([
            'online' => true,
            'last_seen_at' => $presence->last_seen_at?->toIso8601String(),
        ]);
    }

    private function canAccess(Request $request, ProffiChat $chat): bool
    {
        $id = (int) $request->user()->id;

        return (int) $chat->customer_id === $id || (int) $chat->specialist_id === $id;
    }

    private function clearTyping(ProffiChat $chat, int $userId): void
    {
        if ((int) $chat->customer_id === $userId) {
            $chat->update(['customer_typing_at' => null]);
        } else {
            $chat->update(['specialist_typing_at' => null]);
        }
    }

    private function notifyRecipientByEmail(ProffiChat $chat, User $sender, string $text): void
    {
        $recipient = $this->getOtherUser($chat, (int) $sender->id);

        if (!$recipient || !$recipient->email || (int) $recipient->id === (int) $sender->id) {
            return;
        }

        if (ProffiUserPresence::isUserOnline((int) $recipient->id)) {
            return;
        }

        $chat->loadMissing('task');
        $frontendUrl = rtrim(env('PROFFI_FRONTEND_URL', config('app.url')), '/');
        $chatUrl = $frontendUrl . '/chat/' . $chat->id;

        Mail::to($recipient->email)->send(new ProffiNewMessageMail(
            senderName: $sender->name,
            messageText: $text,
            taskTitle: $chat->task?->title ?? 'Заказ',
            chatUrl: $chatUrl,
        ));
    }

    private function getOtherUser(ProffiChat $chat, int $userId): ?User
    {
        if ((int) $chat->customer_id === $userId) {
            return $chat->specialist;
        }

        if ((int) $chat->specialist_id === $userId) {
            return $chat->customer;
        }

        return null;
    }

    private function mapChat(ProffiChat $chat, int $currentUserId): array
    {
        $otherUserId = (int) $chat->customer_id === $currentUserId
            ? (int) $chat->specialist_id
            : (int) $chat->customer_id;

        $presence = ProffiUserPresence::where('user_id', $otherUserId)->first();
        $otherIsOnline = $presence
            && $presence->is_online
            && $presence->last_seen_at
            && $presence->last_seen_at->greaterThan(now()->subMinutes(2));

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
            'unread_count' => $this->unreadCount($chat, $currentUserId),
            'other_is_online' => $otherIsOnline,
            'other_last_seen_at' => optional($presence?->last_seen_at)->toIso8601String(),
            'is_typing' => $this->isOtherTyping($chat, $currentUserId),
            'task_status' => $chat->task?->status,
            'created_at' => optional($chat->created_at)->toIso8601String(),
            'updated_at' => optional($chat->updated_at)->toIso8601String(),
        ];
    }

    private function unreadCount(ProffiChat $chat, int $currentUserId): int
    {
        return $chat->messages()
            ->where('sender_id', '!=', $currentUserId)
            ->whereNull('read_at')
            ->count();
    }

    private function isOtherTyping(ProffiChat $chat, int $currentUserId): bool
    {
        $typingAt = (int) $chat->customer_id === $currentUserId
            ? $chat->specialist_typing_at
            : $chat->customer_typing_at;

        if (!$typingAt) {
            return false;
        }

        return $typingAt->greaterThan(now()->subSeconds(self::TYPING_TTL_SECONDS));
    }

    private function mapMessage(ProffiMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'chat_id' => (string) $message->chat_id,
            'sender_id' => (string) $message->sender_id,
            'user_id' => (string) $message->sender_id,
            'text' => $message->text,
            'type' => $message->type ?? 'text',
            'created_at' => optional($message->created_at)->toIso8601String(),
            'delivered_at' => optional($message->delivered_at)->toIso8601String(),
            'read_at' => optional($message->read_at)->toIso8601String(),
        ];
    }
}
