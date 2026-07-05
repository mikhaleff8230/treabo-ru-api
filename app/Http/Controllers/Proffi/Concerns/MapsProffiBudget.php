<?php

namespace App\Http\Controllers\Proffi\Concerns;

use App\Models\ProffiTask;

trait MapsProffiBudget
{
    protected function normalizeBudgetInput(array $data): array
    {
        $type = $data['budget_type'] ?? 'fixed';

        if ($type === 'range') {
            $min = isset($data['budget_min']) ? (int) $data['budget_min'] : null;
            $max = isset($data['budget_max']) ? (int) $data['budget_max'] : null;

            return [
                'budget_type' => 'range',
                'budget' => null,
                'budget_min' => $min,
                'budget_max' => $max,
            ];
        }

        $budget = isset($data['budget']) ? (int) $data['budget'] : null;

        return [
            'budget_type' => 'fixed',
            'budget' => $budget,
            'budget_min' => null,
            'budget_max' => null,
        ];
    }

    protected function budgetFields(ProffiTask $task): array
    {
        $type = $task->budget_type ?? 'fixed';

        if ($type === 'range') {
            return [
                'budget_type' => 'range',
                'budget' => null,
                'budget_min' => $task->budget_min !== null ? (int) $task->budget_min : null,
                'budget_max' => $task->budget_max !== null ? (int) $task->budget_max : null,
                'budget_label' => $this->formatBudgetLabel($type, null, $task->budget_min, $task->budget_max),
            ];
        }

        $budget = $task->budget !== null ? (int) $task->budget : null;

        return [
            'budget_type' => 'fixed',
            'budget' => $budget,
            'budget_min' => null,
            'budget_max' => null,
            'budget_label' => $this->formatBudgetLabel('fixed', $budget, null, null),
        ];
    }

    protected function formatBudgetLabel(string $type, ?int $budget, $min, $max): ?string
    {
        if ($type === 'range' && $min !== null && $max !== null) {
            return sprintf('от %s до %s ₽', number_format((int) $min, 0, '.', ' '), number_format((int) $max, 0, '.', ' '));
        }

        if ($type === 'range' && $min !== null) {
            return sprintf('от %s ₽', number_format((int) $min, 0, '.', ' '));
        }

        if ($budget !== null && $budget > 0) {
            return number_format($budget, 0, '.', ' ') . ' ₽';
        }

        return null;
    }
}
