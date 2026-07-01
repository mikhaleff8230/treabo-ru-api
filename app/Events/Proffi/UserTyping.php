<?php

namespace App\Events\Proffi;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $userId,
        public bool $isTyping,
        public string $typingAt
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('proffi.chat.' . $this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => (string) $this->chatId,
            'user_id' => (string) $this->userId,
            'is_typing' => $this->isTyping,
            'typing_at' => $this->typingAt,
        ];
    }
}
