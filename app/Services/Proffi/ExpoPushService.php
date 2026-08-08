<?php

namespace App\Services\Proffi;

use App\Models\ProffiPushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = ProffiPushToken::where('user_id', $userId)->pluck('token')->all();
        if (!$tokens) return;

        $messages = array_map(fn (string $token) => [
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => mb_substr($body, 0, 180),
            'data' => $data,
            'channelId' => 'messages',
        ], $tokens);

        try {
            $response = Http::timeout(8)->acceptJson()->post('https://exp.host/--/api/v2/push/send', $messages);
            if (!$response->successful()) {
                Log::warning('Expo push rejected', ['status' => $response->status(), 'body' => $response->json()]);
                return;
            }

            $tickets = $response->json('data', []);
            if (isset($tickets['status'])) $tickets = [$tickets];
            foreach ($tickets as $index => $ticket) {
                if (($ticket['status'] ?? null) !== 'error') continue;
                Log::warning('Expo push ticket failed', [
                    'user_id' => $userId,
                    'token_suffix' => substr($tokens[$index] ?? '', -12),
                    'error' => $ticket['details']['error'] ?? null,
                    'message' => $ticket['message'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Expo push failed', ['error' => $e->getMessage()]);
        }
    }
}
