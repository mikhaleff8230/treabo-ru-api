<?php

namespace App\Services\Proffi;

use App\Models\ProffiPushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Pool;

class ExpoPushService
{
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = ProffiPushToken::where('user_id', $userId)->pluck('token')->all();
        if (!$tokens) return;

        try {
            // Expo rejects a batch when it contains tokens from different EAS
            // projects. Users can retain old tokens after an app migration, so
            // send concurrently but keep every token in its own request.
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $token) => $pool->timeout(8)->acceptJson()->post(
                    'https://exp.host/--/api/v2/push/send',
                    [
                        'to' => $token,
                        'sound' => 'default',
                        'title' => $title,
                        'body' => mb_substr($body, 0, 180),
                        'data' => $data,
                        'channelId' => 'messages',
                    ]
                ),
                $tokens
            ));

            foreach ($responses as $index => $response) {
                $ticket = $response->json('data', []);
                if ($response->successful() && ($ticket['status'] ?? null) !== 'error') continue;
                Log::warning('Expo push ticket failed', [
                    'user_id' => $userId,
                    'token_suffix' => substr($tokens[$index] ?? '', -12),
                    'status' => $response->status(),
                    'error' => $ticket['details']['error'] ?? null,
                    'message' => $ticket['message'] ?? $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Expo push failed', ['error' => $e->getMessage()]);
        }
    }
}
