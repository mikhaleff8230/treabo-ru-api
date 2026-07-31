<?php

return [
    'enabled' => env('AI_REQUEST_ASSISTANT_ENABLED', true),
    'model' => env('AI_REQUEST_ASSISTANT_MODEL', 'gpt-4.1-mini'),
    'prompt_version' => env('AI_REQUEST_ASSISTANT_PROMPT_VERSION', 'request-assistant-v1'),
    'max_ai_calls' => (int) env('AI_REQUEST_ASSISTANT_MAX_CALLS', 6),
    'soft_question_limit' => (int) env('AI_REQUEST_ASSISTANT_SOFT_QUESTIONS', 5),
    'warning_cost_usd' => (float) env('AI_REQUEST_ASSISTANT_WARNING_COST_USD', 0.06),
    'hard_cost_usd' => (float) env('AI_REQUEST_ASSISTANT_HARD_COST_USD', 0.08),
    'draft_ttl_days' => (int) env('AI_REQUEST_ASSISTANT_DRAFT_TTL_DAYS', 30),
    'category_confidence' => (float) env('AI_REQUEST_ASSISTANT_CATEGORY_CONFIDENCE', 0.55),
    'service_confidence' => (float) env('AI_REQUEST_ASSISTANT_SERVICE_CONFIDENCE', 0.65),
    'max_output_tokens' => (int) env('AI_REQUEST_ASSISTANT_MAX_OUTPUT_TOKENS', 1000),
    'pricing' => [
        'input_per_million' => (float) env('AI_REQUEST_ASSISTANT_INPUT_PER_MILLION', 0.40),
        'cached_input_per_million' => (float) env('AI_REQUEST_ASSISTANT_CACHED_INPUT_PER_MILLION', 0.10),
        'output_per_million' => (float) env('AI_REQUEST_ASSISTANT_OUTPUT_PER_MILLION', 1.60),
    ],
];
