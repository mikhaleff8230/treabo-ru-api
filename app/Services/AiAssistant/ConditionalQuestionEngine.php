<?php

namespace App\Services\AiAssistant;

use App\Models\ProffiQuestionRule;
use App\Models\ProffiWorkQuestion;
use Illuminate\Support\Collection;

class ConditionalQuestionEngine
{
    /**
     * @param  array<int|string, mixed>  $answers Values keyed by question id or field_key.
     * @return Collection<int, ProffiWorkQuestion>
     */
    public function activeQuestions(int $workId, array $answers): Collection
    {
        $questions = ProffiWorkQuestion::query()
            ->where('work_id', $workId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $state = $questions->mapWithKeys(fn (ProffiWorkQuestion $question) => [
            $question->id => [
                'visible' => $question->default_visibility !== 'conditional',
                'required' => (bool) $question->is_required,
            ],
        ])->all();

        $rules = ProffiQuestionRule::query()
            ->where('work_id', $workId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (!$this->matches($rule->conditions ?? [], $rule->match_type, $answers, $questions)) {
                continue;
            }
            foreach ($rule->actions ?? [] as $action) {
                $questionId = (int) ($action['question_id'] ?? 0);
                if (!$questionId || !isset($state[$questionId])) {
                    continue;
                }
                $effect = $action['effect'] ?? '';
                if ($effect === 'show') {
                    $state[$questionId]['visible'] = true;
                } elseif ($effect === 'hide') {
                    $state[$questionId]['visible'] = false;
                } elseif ($effect === 'require') {
                    $state[$questionId]['visible'] = true;
                    $state[$questionId]['required'] = true;
                } elseif ($effect === 'optional') {
                    $state[$questionId]['required'] = false;
                }
            }
        }

        return $questions
            ->filter(fn ($question) => $state[$question->id]['visible'])
            ->each(function ($question) use ($state) {
                $question->setAttribute('effective_required', $state[$question->id]['required']);
            })
            ->values();
    }

    private function matches(array $conditions, string $matchType, array $answers, Collection $questions): bool
    {
        if ($conditions === []) {
            return false;
        }
        $results = collect($conditions)->map(function (array $condition) use ($answers, $questions) {
            $questionId = (int) ($condition['question_id'] ?? 0);
            $fieldKey = (string) ($condition['field_key'] ?? '');
            $question = $questionId ? $questions->firstWhere('id', $questionId) : null;
            $key = $questionId ?: ($fieldKey ?: $question?->field_key);
            $actual = $answers[$key] ?? ($question?->field_key ? ($answers[$question->field_key] ?? null) : null);
            $expected = $condition['value'] ?? null;

            return match ($condition['operator'] ?? 'equals') {
                'not_equals' => $actual != $expected,
                'in' => in_array($actual, (array) $expected, true),
                'not_in' => !in_array($actual, (array) $expected, true),
                'contains' => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
                'exists' => $actual !== null && $actual !== '',
                'not_exists' => $actual === null || $actual === '',
                'greater_than' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
                'less_than' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
                default => $actual == $expected,
            };
        });

        return $matchType === 'any' ? $results->contains(true) : !$results->contains(false);
    }
}
