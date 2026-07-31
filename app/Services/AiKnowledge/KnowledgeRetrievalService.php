<?php

namespace App\Services\AiKnowledge;

use App\Models\AiKnowledgeTerm;
use App\Models\AiKnowledgeVersion;
use App\Models\ProffiCategory;
use App\Models\ProffiWork;
use App\Models\ProffiWorkQuestion;
use Illuminate\Support\Collection;

class KnowledgeRetrievalService
{
    public function __construct(private readonly KnowledgeTextNormalizer $normalizer)
    {
    }

    public function retrieve(
        string $text,
        int $limit = 10,
        bool $publishedOnly = true,
        ?AiKnowledgeVersion $version = null
    ): array
    {
        $normalized = $this->normalizer->normalize($text);
        $tokens = $this->tokens($normalized);
        $limit = max(1, min(30, $limit));
        $version ??= AiKnowledgeVersion::query()
            ->where('status', $publishedOnly ? 'published' : 'draft')
            ->latest('id')
            ->first();

        $works = ProffiWork::query()
            ->with('category')
            ->where('is_active', true)
            ->get(['id', 'category_id', 'title', 'slug', 'aliases', 'description']);

        $termSignals = $version
            ? AiKnowledgeTerm::query()
                ->with(['variants', 'links'])
                ->where('knowledge_version_id', $version->id)
                ->whereIn('status', $version->status === 'published' ? ['published'] : ['draft', 'published'])
                ->get()
            : collect();

        $scores = [];
        $evidence = [];
        foreach ($works as $work) {
            $phrases = collect([$work->title, $work->slug, ...($work->aliases ?? [])])
                ->filter()
                ->map(fn ($phrase) => $this->normalizer->normalize((string) $phrase));
            $score = $phrases->max(fn ($phrase) => $this->lexicalScore($normalized, $tokens, $phrase)) ?? 0;
            if ($score > 0) {
                $scores[$work->id] = $score;
                $evidence[$work->id][] = ['source' => 'catalog', 'text' => $work->title, 'score' => $score];
            }
        }

        foreach ($termSignals as $term) {
            $phrases = collect([$term->display_text, ...$term->variants->pluck('variant_text')->all()]);
            $termScore = $phrases->max(
                fn ($phrase) => $this->lexicalScore(
                    $normalized,
                    $tokens,
                    $this->normalizer->normalize((string) $phrase)
                )
            ) ?? 0;
            if ($termScore <= 0) {
                continue;
            }
            foreach ($term->links as $link) {
                if ($link->target_type !== 'service') {
                    continue;
                }
                $id = (int) $link->target_id;
                $weighted = $termScore * $link->weight;
                if ($link->relation === 'excludes') {
                    $scores[$id] = ($scores[$id] ?? 0) - $weighted;
                } else {
                    $scores[$id] = max($scores[$id] ?? 0, $weighted);
                    $evidence[$id][] = [
                        'source' => 'knowledge_term',
                        'text' => $term->display_text,
                        'relation' => $link->relation,
                        'score' => round($weighted, 4),
                    ];
                }
            }
        }

        $rankedIds = collect($scores)
            ->filter(fn ($score) => $score > 0.05)
            ->sortDesc()
            ->take($limit)
            ->keys()
            ->map(fn ($id) => (int) $id);
        $workMap = $works->keyBy('id');
        $topId = $rankedIds->first();
        if ($topId && ($scores[$topId] ?? 0) < 0.55) {
            $topCategoryId = $workMap->get($topId)?->category_id;
            $fallbackIds = $works
                ->where('category_id', $topCategoryId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);
            foreach ($fallbackIds as $fallbackId) {
                if (!$rankedIds->contains($fallbackId) && $rankedIds->count() < $limit) {
                    $rankedIds->push($fallbackId);
                    $scores[$fallbackId] = $scores[$fallbackId] ?? 0.05;
                    $evidence[$fallbackId][] = [
                        'source' => 'category_fallback',
                        'text' => $workMap->get($fallbackId)?->title,
                        'score' => 0.05,
                    ];
                }
            }
        }

        $candidates = $rankedIds->map(function ($id) use ($workMap, $scores, $evidence) {
            $work = $workMap->get($id);
            if (!$work) {
                return null;
            }

            return [
                'work_id' => $work->id,
                'category_id' => $work->category_id,
                'title' => $work->title,
                'slug' => $work->slug,
                'aliases' => $work->aliases ?? [],
                'score' => round(max(0, min(1, $scores[$id] ?? 0)), 4),
                'evidence' => array_slice($evidence[$id] ?? [], 0, 5),
            ];
        })->filter()->values();

        $categoryIds = $candidates->pluck('category_id')->filter()->unique();
        $categories = ProffiCategory::query()
            ->whereIn('id', $categoryIds)
            ->where('is_active', true)
            ->get(['id', 'slug', 'name_ru', 'parent_id'])
            ->values();
        $questions = ProffiWorkQuestion::query()
            ->whereIn('work_id', $candidates->pluck('work_id'))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'work_id', 'field_key', 'question', 'type', 'options', 'is_required'])
            ->values();

        return [
            'knowledge_version_id' => $version?->id,
            'knowledge_version' => $version?->version,
            'query_normalized' => $normalized,
            'categories' => $categories->all(),
            'works' => $candidates->all(),
            'questions' => $questions->all(),
        ];
    }

    private function lexicalScore(string $query, Collection $queryTokens, string $candidate): float
    {
        if ($candidate === '' || $query === '') {
            return 0;
        }
        if ($query === $candidate) {
            return 1;
        }
        if (str_contains($query, $candidate)) {
            return 0.9;
        }

        $candidateTokens = $this->tokens($candidate);
        if ($candidateTokens->isEmpty()) {
            return 0;
        }
        $intersection = $queryTokens->intersect($candidateTokens)->count();
        $stemIntersection = $queryTokens
            ->map(fn ($token) => mb_substr($token, 0, min(5, mb_strlen($token))))
            ->intersect(
                $candidateTokens->map(fn ($token) => mb_substr($token, 0, min(5, mb_strlen($token))))
            )
            ->count();
        $union = $queryTokens->merge($candidateTokens)->unique()->count();
        $jaccard = $union ? max($intersection, $stemIntersection * 0.8) / $union : 0;

        similar_text($query, $candidate, $similarity);
        if ($intersection === 0 && $stemIntersection === 0 && $similarity < 60) {
            return 0;
        }

        return min(0.85, ($jaccard * 0.65) + (($similarity / 100) * 0.35));
    }

    private function tokens(string $text): Collection
    {
        return collect(preg_split('/\s+/u', $text) ?: [])
            ->filter(fn ($token) => mb_strlen($token) >= 2)
            ->unique()
            ->values();
    }
}
