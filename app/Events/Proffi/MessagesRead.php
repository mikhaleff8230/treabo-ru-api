<?php

namespace App\Events\Proffi;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $readerId,
        public string $readAt
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('proffi.chat.' . $this->chatId);
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => (string) $this->chatId,
            'reader_id' => (string) $this->readerId,
            'read_at' => $this->readAt,
        ];
    }
}
