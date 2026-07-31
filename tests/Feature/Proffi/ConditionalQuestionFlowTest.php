<?php

namespace Tests\Feature\Proffi;

use App\Models\ProffiCategory;
use App\Models\ProffiQuestionRule;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use App\Services\AiAssistant\ConditionalQuestionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionalQuestionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_conditional_question_is_hidden_then_required_when_rule_matches(): void
    {
        ProffiCategory::create([
            'id' => 'plumbing',
            'slug' => 'plumbing',
            'name_ru' => 'Сантехника',
            'is_active' => true,
        ]);
        $work = ProffiWork::create([
            'category_id' => 'plumbing',
            'slug' => 'toilet-repair',
            'title' => 'Ремонт унитаза',
            'is_active' => true,
        ]);
        $problem = ProffiWorkQuestion::create([
            'work_id' => $work->id,
            'field_key' => 'problem',
            'question' => 'Что случилось?',
            'type' => 'select',
            'options' => ['Протечка', 'Крепление'],
            'is_required' => true,
            'default_visibility' => 'always',
            'is_active' => true,
        ]);
        $water = ProffiWorkQuestion::create([
            'work_id' => $work->id,
            'field_key' => 'water_shutoff',
            'question' => 'Можно перекрыть воду?',
            'type' => 'yesno',
            'is_required' => false,
            'default_visibility' => 'conditional',
            'is_active' => true,
        ]);
        ProffiQuestionRule::create([
            'work_id' => $work->id,
            'name' => 'Безопасность при протечке',
            'match_type' => 'all',
            'conditions' => [[
                'question_id' => $problem->id,
                'operator' => 'equals',
                'value' => 'Протечка',
            ]],
            'actions' => [[
                'question_id' => $water->id,
                'effect' => 'require',
            ]],
            'is_active' => true,
        ]);

        $engine = app(ConditionalQuestionEngine::class);
        $initial = $engine->activeQuestions($work->id, []);
        $leak = $engine->activeQuestions($work->id, ['problem' => 'Протечка']);

        $this->assertSame([$problem->id], $initial->pluck('id')->all());
        $this->assertSame([$problem->id, $water->id], $leak->pluck('id')->all());
        $this->assertTrue((bool) $leak->firstWhere('id', $water->id)->effective_required);
    }
}
