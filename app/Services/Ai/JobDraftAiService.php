<?php

namespace App\Services\Ai;

use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use App\Models\AiChatKnowledge;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JobDraftAiService
{
    private const LANGUAGES = ['ru', 'unknown'];
    private const URGENCIES = ['urgent', 'this_week', 'this_month', 'flexible', 'unknown'];

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
            'reasoning' => ['effort' => 'low'],
            'max_output_tokens' => 1800,
            'instructions' => $this->systemPrompt($data),
            'input' => $this->userPrompt($data),
            'text' => [
                'verbosity' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'treabo_job_draft',
                    'strict' => true,
                    'schema' => $this->responseSchema(),
                ],
            ],
        ];

        try {
            $responsesUrl = rtrim(
                (string) config('services.openai.base_url', 'https://api.openai.com/v1'),
                '/'
            ).'/responses';

            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(45)
                ->post($responsesUrl, $payload);
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
        $this->tokensUsed = isset($body['usage'])
            ? (int) (($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0))
            : null;
        $content = $this->responseOutputText($body);

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

        return $this->normalizeDraft($decoded, $data);
    }

    public function model(): string
    {
        return (string) config('services.openai.model', 'gpt-5.6-luna');
    }

    public function tokensUsed(): ?int
    {
        return $this->tokensUsed;
    }

    private function normalizeDraft(array $draft, array $input = []): array
    {
        $language = $this->enum($draft['detected_language'] ?? 'unknown', self::LANGUAGES, 'unknown');
        $urgency = $this->enum($draft['urgency'] ?? 'unknown', self::URGENCIES, 'unknown');

        $categoryId = $this->resolveCategoryId($draft['category_id'] ?? null);
        $workId = $this->resolveWorkId($draft['work_id'] ?? null, $categoryId);

        $category = $categoryId
            ? ProffiCategory::whereKey($categoryId)->where('is_active', true)->first()
            : null;

        if ($categoryId && !$category) {
            $categoryId = null;
        }

        if ($workId) {
            $work = ProffiWork::whereKey($workId)->where('is_active', true)->first();
            if (!$work || ($categoryId && $work->category_id !== $categoryId)) {
                $workId = null;
            }
        }

        $inputText = (string) ($input['text'] ?? '');
        $inferred = $this->inferWorkFromText($inputText, $categoryId)
            ?: $this->inferWorkFromText($inputText, null);

        if ($inferred && $inferred->id !== $workId) {
            $workId = $inferred->id;
            $categoryId = $inferred->category_id;
            $category = $inferred->category;
        } elseif (!$workId && $inferred) {
            $workId = $inferred->id;
            if (!$categoryId) {
                $categoryId = $inferred->category_id;
                $category = $inferred->category;
            }
        }

        $confidence = $draft['confidence'] ?? 0;
        $confidence = is_numeric($confidence) ? (float) $confidence : 0.0;

        $missingQuestions = $this->resolveMissingQuestions($workId, $draft['missing_questions'] ?? []);

        $categorySlug = $category?->slug ?? $category?->id ?? 'other';

        return [
            'detected_language' => $language,
            'title' => $this->shortText($draft['title'] ?? 'Заявка на услугу', 160),
            'category_id' => $categoryId,
            'work_id' => $workId,
            'category_slug' => $categorySlug,
            'city' => $this->nullableText($draft['city'] ?? null, 100),
            'urgency' => $urgency,
            'description' => $this->shortText($draft['description'] ?? '', 4000),
            'master_summary' => $this->shortText($draft['master_summary'] ?? '', 600),
            'missing_questions' => $missingQuestions,
            'confidence' => max(0, min(1, round($confidence, 2))),
            'assistant_message' => $this->shortText($draft['assistant_message'] ?? '', 500),
            'needs_clarification' => (bool) ($draft['needs_clarification'] ?? false),
        ];
    }

    private function resolveCategoryId(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $id = trim($value);

        return ProffiCategory::whereKey($id)->where('is_active', true)->exists() ? $id : null;
    }

    private function resolveWorkId(mixed $value, ?string $categoryId): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $id = (int) $value;
        $query = ProffiWork::whereKey($id)->where('is_active', true);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        return $query->exists() ? $id : null;
    }

    private function inferWorkFromText(string $text, ?string $categoryId): ?ProffiWork
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        $query = ProffiWork::query()
            ->with('category')
            ->where('is_active', true);

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $works = $query->orderBy('sort_order')->get();
        $best = null;
        $bestScore = 0;

        foreach ($works as $work) {
            $score = 0;
            $title = mb_strtolower($work->title);
            if ($title !== '' && str_contains($text, $title)) {
                $score += 100;
            }

            if ($work->slug && str_contains($text, mb_strtolower($work->slug))) {
                $score += 80;
            }

            foreach ($work->aliases ?? [] as $alias) {
                $alias = mb_strtolower((string) $alias);
                if ($alias !== '' && str_contains($text, $alias)) {
                    $score += 60;
                    continue;
                }

                $aliasWords = preg_split('/\s+/u', $alias) ?: [];
                $matchedAliasWords = 0;
                foreach ($aliasWords as $word) {
                    $stem = mb_substr($word, 0, 4);
                    if (mb_strlen($stem) >= 3 && str_contains($text, $stem)) {
                        $matchedAliasWords++;
                    }
                }
                if ($aliasWords && $matchedAliasWords === count($aliasWords)) {
                    $score += 50;
                }
            }

            foreach (preg_split('/\s+/u', $title) as $word) {
                if (mb_strlen($word) >= 4 && str_contains($text, $word)) {
                    $score += 10;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $work;
            }
        }

        if ($best && $bestScore >= 10) {
            return $best;
        }

        if ($categoryId && $works->count() === 1) {
            return $works->first();
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveMissingQuestions(?int $workId, mixed $aiQuestions): array
    {
        if ($workId) {
            return ProffiWorkQuestion::query()
                ->where('work_id', $workId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (ProffiWorkQuestion $q) => [
                    'question_id' => $q->id,
                    'field_key' => $q->field_key,
                    'question' => $q->question,
                    'type' => $q->type,
                    'options' => $q->options,
                    'placeholder' => $q->placeholder,
                    'help_text' => $q->help_text,
                    'is_required' => $q->is_required,
                ])
                ->values()
                ->all();
        }

        if (!is_array($aiQuestions)) {
            return [];
        }

        $result = [];

        foreach ($aiQuestions as $item) {
            if (is_array($item) && isset($item['question']) && is_string($item['question'])) {
                $result[] = [
                    'question_id' => is_numeric($item['question_id'] ?? null) ? (int) $item['question_id'] : null,
                    'field_key' => is_string($item['field_key'] ?? null) ? $item['field_key'] : null,
                    'question' => $this->shortText($item['question'], 220),
                    'type' => $this->enum($item['type'] ?? 'text', ['text', 'textarea', 'number', 'yesno', 'select', 'multiselect', 'photo'], 'text'),
                    'options' => is_array($item['options'] ?? null) ? $item['options'] : null,
                    'is_required' => (bool) ($item['is_required'] ?? false),
                ];
            } elseif (is_string($item) && trim($item) !== '') {
                $result[] = [
                    'question_id' => null,
                    'field_key' => null,
                    'question' => $this->shortText($item, 220),
                    'type' => 'text',
                    'options' => null,
                    'is_required' => false,
                ];
            }
        }

        return $result;
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

    private function systemPrompt(array $data = []): string
    {
        $basePrompt = <<<'PROMPT'
Ты AI-помощник сервиса Treabo для оформления заявок на услуги мастеров в России.
Пользователь пишет на русском и может писать плохо, коротко или с ошибками.
Твоя задача — превратить хаотичный текст в понятную заявку для мастера.
Не выдумывай факты.
Пиши просто, понятно, без канцелярита.
Ответ возвращай только в JSON по заданной структуре.
Никакого markdown, никакого текста вне JSON.
Выбирай category_id и work_id только из переданных списков. Если не уверен — верни null.
Не придумывай вопросы в missing_questions — backend подставит их из справочника по выбранной работе.
PROMPT;

        $categoryHint = trim((string) ($data['category_hint'] ?? ''));
        $categoryScopes = [$categoryHint];
        if ($categoryHint !== '') {
            $hintCategory = ProffiCategory::query()
                ->where('id', $categoryHint)
                ->orWhere('slug', $categoryHint)
                ->first(['id', 'slug']);
            if ($hintCategory) {
                $categoryScopes = array_values(array_unique([
                    (string) $hintCategory->id,
                    (string) $hintCategory->slug,
                ]));
            }
        }
        $instructions = AiChatKnowledge::query()
            ->where('type', 'instruction')
            ->where('is_active', true)
            ->where(function ($query) use ($categoryHint, $categoryScopes) {
                $query->whereNull('category_slug')->orWhere('category_slug', '');
                if ($categoryHint !== '') {
                    $query->orWhereIn('category_slug', $categoryScopes);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(30)
            ->pluck('content')
            ->filter(fn ($content) => is_string($content) && trim($content) !== '')
            ->map(fn ($content) => trim(mb_substr($content, 0, 2000)))
            ->implode("\n\n");

        if ($instructions === '') {
            return $basePrompt;
        }

        return $basePrompt . "\n\nДополнительные бизнес-инструкции Treabo из админки:\n" . mb_substr($instructions, 0, 10000);
    }

    private function userPrompt(array $data): string
    {
        $catalog = $this->catalogContext();

        $schema = [
            'detected_language' => 'ru|unknown',
            'title' => 'string',
            'category_id' => 'string|null — id из categories',
            'work_id' => 'number|null — id из works',
            'city' => 'string|null',
            'urgency' => 'urgent|this_week|this_month|flexible|unknown',
            'description' => 'string',
            'master_summary' => 'string',
            'missing_questions' => [],
            'confidence' => 'number between 0 and 1',
            'assistant_message' => 'string',
            'needs_clarification' => 'boolean',
        ];

        return json_encode([
            'task' => 'Generate a structured Treabo job draft for masters in Russia. Return only valid JSON matching response_schema.',
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
                'If text mentions Moscow, Москва, SPb, Санкт-Петербург or other Russian cities, normalize city to the proper Russian name.',
                'Default city_hint is Москва when city is unknown.',
                'Choose category_id from categories list. Match by name, slug or context.',
                'Choose work_id from works list. Match by title, slug or aliases.',
                'If unsure about category or work, set category_id/work_id to null and lower confidence.',
                'Do not generate missing_questions — leave as empty array.',
                'Set assistant_message to one concise clarification question when information is missing, otherwise briefly confirm the classification.',
                'Set needs_clarification to true only when another answer would materially improve the request.',
            ],
            'categories' => $catalog['categories'],
            'works' => $catalog['works'],
            'response_schema' => $schema,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'detected_language', 'title', 'category_id', 'work_id', 'city', 'urgency',
                'description', 'master_summary', 'missing_questions', 'confidence',
                'assistant_message', 'needs_clarification',
            ],
            'properties' => [
                'detected_language' => ['type' => 'string', 'enum' => self::LANGUAGES],
                'title' => ['type' => 'string'],
                'category_id' => ['type' => ['string', 'null']],
                'work_id' => ['type' => ['integer', 'null']],
                'city' => ['type' => ['string', 'null']],
                'urgency' => ['type' => 'string', 'enum' => self::URGENCIES],
                'description' => ['type' => 'string'],
                'master_summary' => ['type' => 'string'],
                'missing_questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'assistant_message' => ['type' => 'string'],
                'needs_clarification' => ['type' => 'boolean'],
            ],
        ];
    }

    private function responseOutputText(array $body): ?string
    {
        foreach ($body['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    /**
     * @return array{categories: array<int, array<string, mixed>>, works: array<int, array<string, mixed>>}
     */
    private function catalogContext(): array
    {
        $categories = ProffiCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name_ru')
            ->get(['id', 'slug', 'name_ru', 'parent_id'])
            ->map(fn (ProffiCategory $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'name_ru' => $c->name_ru,
                'parent_id' => $c->parent_id,
            ])
            ->values()
            ->all();

        $works = ProffiWork::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'category_id', 'title', 'slug', 'aliases', 'description'])
            ->map(fn (ProffiWork $w) => [
                'id' => $w->id,
                'category_id' => $w->category_id,
                'title' => $w->title,
                'slug' => $w->slug,
                'aliases' => $w->aliases ?? [],
                'description' => $w->description,
                'questions' => ProffiWorkQuestion::query()
                    ->where('work_id', $w->id)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'field_key', 'question', 'type', 'options', 'is_required'])
                    ->map(fn (ProffiWorkQuestion $q) => [
                        'id' => $q->id,
                        'field_key' => $q->field_key,
                        'question' => $q->question,
                        'type' => $q->type,
                        'options' => $q->options,
                        'is_required' => $q->is_required,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'categories' => $categories,
            'works' => $works,
        ];
    }
}
