<?php

namespace App\Services\Ai;

use App\Models\AiChatKnowledge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobDraftAiService
{
    private const LANGUAGES = ['ru', 'ro', 'mixed', 'unknown'];
    private const URGENCIES = ['urgent', 'this_week', 'this_month', 'flexible', 'unknown'];
    private const CATEGORIES = [
        'bathroom-renovation',
        'tile-work',
        'plumbing',
        'electrical',
        'air-conditioners',
        'other',
    ];

    private ?int $tokensUsed = null;

    public function generateDraft(array $data): array
    {
        $apiKey = config('services.openai.api_key');
        $model = $this->model();

        if (!$apiKey) {
            throw new JobDraftAiException('OpenAI API key is not configured.');
        }

        $payload = [
            'model' => $model,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->userPrompt($data)],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (\Throwable $e) {
            Log::error('OpenAI job draft request failed', [
                'message' => $e->getMessage(),
                'model' => $model,
            ]);

            throw new JobDraftAiException('OpenAI request failed.', 0, $e);
        }

        if (!$response->successful()) {
            Log::error('OpenAI job draft returned non-success status', [
                'status' => $response->status(),
                'body' => $response->body(),
                'model' => $model,
            ]);

            throw new JobDraftAiException('OpenAI returned an error.');
        }

        $body = $response->json();
        $this->tokensUsed = $body['usage']['total_tokens'] ?? null;
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            Log::error('OpenAI job draft empty content', [
                'response' => $body,
                'model' => $model,
            ]);

            throw new JobDraftAiException('OpenAI returned empty content.');
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            Log::error('OpenAI job draft invalid JSON', [
                'content' => $content,
                'json_error' => json_last_error_msg(),
                'model' => $model,
            ]);

            throw new JobDraftAiException('OpenAI returned invalid JSON.');
        }

        return $this->normalizeDraft($decoded);
    }

    public function model(): string
    {
        return (string) config('services.openai.model', 'gpt-4o-mini');
    }

    public function tokensUsed(): ?int
    {
        return $this->tokensUsed;
    }

    private function normalizeDraft(array $draft): array
    {
        $language = $this->enum($draft['detected_language'] ?? 'unknown', self::LANGUAGES, 'unknown');
        $urgency = $this->enum($draft['urgency'] ?? 'unknown', self::URGENCIES, 'unknown');
        $category = $this->enum($draft['category_slug'] ?? 'other', self::CATEGORIES, 'other');

        $questions = $draft['missing_questions'] ?? [];
        if (!is_array($questions)) {
            $questions = [];
        }

        $confidence = $draft['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? (float) $confidence : 0.0;

        return [
            'detected_language' => $language,
            'title' => $this->shortText($draft['title'] ?? 'Заявка на услугу', 160),
            'category_slug' => $category,
            'city' => $this->nullableText($draft['city'] ?? null, 100),
            'urgency' => $urgency,
            'description' => $this->shortText($draft['description'] ?? '', 4000),
            'master_summary' => $this->shortText($draft['master_summary'] ?? '', 600),
            'missing_questions' => array_values(array_map(
                fn ($question) => $this->shortText($question, 220),
                array_filter($questions, fn ($question) => is_string($question) && trim($question) !== '')
            )),
            'confidence' => max(0, min(1, round($confidence, 2))),
        ];
    }

    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function shortText(mixed $value, int $limit): string
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, $limit);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты AI-помощник сервиса Treabo для оформления заявок на услуги мастеров в Молдове.
Пользователь может писать плохо, коротко, с ошибками, на русском, румынском или смешанно.
Твоя задача — превратить хаотичный текст в понятную заявку для мастера.
Не выдумывай факты.
Если данных нет — добавь вопрос в missing_questions.
Пиши просто, понятно, без канцелярита.
Ответ возвращай только в JSON по заданной структуре.
Никакого markdown, никакого текста вне JSON.
PROMPT;
    }

    private function userPrompt(array $data): string
    {
        $schema = [
            'detected_language' => 'ru|ro|mixed|unknown',
            'title' => 'string',
            'category_slug' => 'bathroom-renovation|tile-work|plumbing|electrical|air-conditioners|other',
            'city' => 'string|null',
            'urgency' => 'urgent|this_week|this_month|flexible|unknown',
            'description' => 'string',
            'master_summary' => 'string',
            'missing_questions' => ['string'],
            'confidence' => 'number between 0 and 1',
        ];

        return json_encode([
            'task' => 'Generate a structured Treabo job draft for masters in Moldova. Return only valid JSON matching response_schema.',
            'input' => [
                'text' => $data['text'] ?? '',
                'city_hint' => $data['city_hint'] ?? null,
                'category_hint' => $data['category_hint'] ?? null,
                'language_hint' => $data['language_hint'] ?? 'auto',
            ],
            'rules' => [
                'Do not publish the job.',
                'Do not invent facts.',
                'Use city_hint/category_hint only as hints, not as guaranteed facts.',
                'If text mentions Kishinev, Chisinau, Chisinău, Кишинев or Кишинёв, normalize city to Chișinău.',
                'If important fields are missing, add clear questions to missing_questions.',
                'For bathroom/tile/plumbing/electrical/air conditioner work choose the closest allowed category_slug.',
                'Use ai_knowledge as Treabo internal knowledge. Prefer matching categories, works, parameters and questions from it.',
                'If ai_knowledge contains required parameters for the detected work, ask about missing required parameters in missing_questions.',
            ],
            'ai_knowledge' => $this->knowledgeContext($data),
            'response_schema' => $schema,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function knowledgeContext(array $data): array
    {
        $text = mb_strtolower((string) ($data['text'] ?? ''));
        $categoryHint = (string) ($data['category_hint'] ?? '');

        return AiChatKnowledge::query()
            ->where('is_active', true)
            ->where(function ($query) use ($text, $categoryHint) {
                $query->whereNull('category_slug');

                if ($categoryHint !== '') {
                    $query->orWhere('category_slug', $categoryHint);
                }

                if (str_contains($text, 'ванн') || str_contains($text, 'сануз') || str_contains($text, 'baie')) {
                    $query->orWhere('category_slug', 'bathroom-renovation');
                }

                if (str_contains($text, 'плит') || str_contains($text, 'кафел') || str_contains($text, 'gresie')) {
                    $query->orWhere('category_slug', 'tile-work')
                        ->orWhere('work_slug', 'tile-work');
                }

                if (str_contains($text, 'сантех') || str_contains($text, 'труб') || str_contains($text, 'instalator')) {
                    $query->orWhere('category_slug', 'plumbing');
                }

                if (str_contains($text, 'элект') || str_contains($text, 'розет') || str_contains($text, 'electric')) {
                    $query->orWhere('category_slug', 'electrical');
                }

                if (str_contains($text, 'кондицион') || str_contains($text, 'aer conditionat')) {
                    $query->orWhere('category_slug', 'air-conditioners');
                }
            })
            ->orderBy('sort_order')
            ->limit(40)
            ->get()
            ->map(fn (AiChatKnowledge $item) => [
                'type' => $item->type,
                'category_slug' => $item->category_slug,
                'work_slug' => $item->work_slug,
                'title' => $item->title,
                'slug' => $item->slug,
                'content' => $item->content,
                'payload' => $item->payload,
            ])
            ->values()
            ->all();
    }
}
