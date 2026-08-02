<?php

return [
    'model' => env('AI_KNOWLEDGE_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
    'prompt_version' => env('AI_KNOWLEDGE_PROMPT_VERSION', 'knowledge-cluster-v2'),
    'max_rows_per_import' => (int) env('AI_KNOWLEDGE_MAX_ROWS', 10000),
    'cluster_size' => (int) env('AI_KNOWLEDGE_CLUSTER_SIZE', 40),
    'max_output_tokens' => (int) env('AI_KNOWLEDGE_MAX_OUTPUT_TOKENS', 1800),
    'default_cost_limit_usd' => (float) env('AI_KNOWLEDGE_DEFAULT_COST_LIMIT_USD', 2),
    'pricing' => [
        'input_per_million' => (float) env('AI_KNOWLEDGE_INPUT_PRICE', 0.40),
        'cached_input_per_million' => (float) env('AI_KNOWLEDGE_CACHED_INPUT_PRICE', 0.10),
        'output_per_million' => (float) env('AI_KNOWLEDGE_OUTPUT_PRICE', 1.60),
    ],
];
