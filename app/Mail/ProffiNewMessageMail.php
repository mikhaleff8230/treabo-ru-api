<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProffiNewMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $messageText,
        public string $taskTitle,
        public string $chatUrl,
    ) {
    }

    public function build(): self
    {
        $preview = mb_strlen($this->messageText) > 120
            ? mb_substr($this->messageText, 0, 120) . '…'
            : $this->messageText;

        return $this
            ->subject('Новое сообщение по заказу: ' . $this->taskTitle)
            ->markdown('emails.proffi.new-message', [
                'senderName' => $this->senderName,
                'messageText' => $preview,
                'taskTitle' => $this->taskTitle,
                'chatUrl' => $this->chatUrl,
            ]);
    }
}
