<?php

namespace App\Http\Controllers\Proffi;

use App\Http\Controllers\Controller;
use App\Models\ProffiQuestionGroup;
use App\Models\ProffiQuestionRule;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use App\Services\AiAssistant\ConditionalQuestionEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionFlowController extends Controller
{
    public function index(Request $request)
    {
        $workId = $request->validate(['work_id' => ['required', 'integer', 'exists:proffi_works,id']])['work_id'];

        return [
            'groups' => ProffiQuestionGroup::where('work_id', $workId)->orderBy('sort_order')->get(),
            'rules' => ProffiQuestionRule::where('work_id', $workId)->orderBy('priority')->get(),
            'questions' => ProffiWorkQuestion::where('work_id', $workId)->orderBy('sort_order')->get(),
        ];
    }

    public function storeGroup(Request $request)
    {
        return response()->json(ProffiQuestionGroup::create($this->groupData($request)), 201);
    }

    public function updateGroup(Request $request, ProffiQuestionGroup $group)
    {
        $group->update($this->groupData($request));

        return $group->fresh();
    }

    public function destroyGroup(ProffiQuestionGroup $group)
    {
        $group->delete();

        return ['ok' => true];
    }

    public function storeRule(Request $request)
    {
        return response()->json(ProffiQuestionRule::create($this->ruleData($request)), 201);
    }

    public function updateRule(Request $request, ProffiQuestionRule $rule)
    {
        $rule->update($this->ruleData($request));

        return $rule->fresh();
    }

    public function destroyRule(ProffiQuestionRule $rule)
    {
        $rule->delete();

        return ['ok' => true];
    }

    public function preview(Request $request, ConditionalQuestionEngine $engine)
    {
        $data = $request->validate([
            'work_id' => ['required', 'integer', 'exists:proffi_works,id'],
            'answers' => ['nullable', 'array', 'max:200'],
        ]);
        $questions = $engine->activeQuestions((int) $data['work_id'], $data['answers'] ?? []);

        return [
            'questions' => $questions->map(fn ($question) => [
                ...$question->toArray(),
                'effective_required' => (bool) $question->effective_required,
            ])->values(),
        ];
    }

    private function groupData(Request $request): array
    {
        return $request->validate([
            'work_id' => ['required', 'integer', 'exists:proffi_works,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['sort_order' => 0, 'is_active' => true];
    }

    private function ruleData(Request $request): array
    {
        $data = $request->validate([
            'work_id' => ['required', 'integer', 'exists:proffi_works,id'],
            'name' => ['required', 'string', 'max:255'],
            'match_type' => ['required', Rule::in(['all', 'any'])],
            'conditions' => ['required', 'array', 'min:1', 'max:20'],
            'conditions.*.question_id' => ['required', 'integer', 'exists:proffi_work_questions,id'],
            'conditions.*.operator' => ['required', Rule::in([
                'equals', 'not_equals', 'in', 'not_in', 'contains', 'exists', 'not_exists',
                'greater_than', 'less_than',
            ])],
            'conditions.*.value' => ['present'],
            'actions' => ['required', 'array', 'min:1', 'max:20'],
            'actions.*.question_id' => ['required', 'integer', 'exists:proffi_work_questions,id'],
            'actions.*.effect' => ['required', Rule::in(['show', 'hide', 'require', 'optional'])],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $questionIds = collect($data['conditions'])->pluck('question_id')
            ->merge(collect($data['actions'])->pluck('question_id'))->unique();
        $validCount = ProffiWorkQuestion::where('work_id', $data['work_id'])
            ->whereIn('id', $questionIds)->count();
        if ($validCount !== $questionIds->count()) {
            throw ValidationException::withMessages([
                'conditions' => 'Все условия и действия должны ссылаться на вопросы выбранной работы.',
            ]);
        }

        $data['priority'] = $data['priority'] ?? 100;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
