<?php

namespace App\Events\Proffi;

use App\Models\ProffiChat;
use App\Models\ProffiMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProffiMessage $message,
        public ProffiChat $chat
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('proffi.chat.' . $this->chat->id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => (string) $this->chat->id,
            'message' => [
                'id' => (string) $this->message->id,
                'chat_id' => (string) $this->message->chat_id,
                'sender_id' => (string) $this->message->sender_id,
                'user_id' => (string) $this->message->sender_id,
                'text' => $this->message->text,
                'type' => $this->message->type ?? 'text',
                'created_at' => optional($this->message->created_at)->toIso8601String(),
                'delivered_at' => optional($this->message->delivered_at)->toIso8601String(),
                'read_at' => optional($this->message->read_at)->toIso8601String(),
            ],
            'chat' => [
                'id' => (string) $this->chat->id,
                'last_message' => $this->chat->last_message,
                'last_message_at' => optional($this->chat->last_message_at)->toIso8601String(),
            ],
        ];
    }
}
