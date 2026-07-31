<?php

namespace App\Services\AiKnowledge;

use Illuminate\Support\Str;

class KnowledgeTextNormalizer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $text, ?string $defaultRegion = null): array
    {
        $lines = preg_split('/\R/u', str_replace("\0", '', $text)) ?: [];
        $result = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$phrase, $frequency, $region, $period] = $this->parseLine($line);
            $normalized = $this->normalize($phrase);

            if ($normalized === '') {
                continue;
            }

            $result[] = [
                'row_no' => $index + 1,
                'raw_text' => $phrase,
                'normalized_text' => $normalized,
                'redacted_text' => $this->redactPii($phrase),
                'frequency' => $frequency,
                'region' => $region ?: $defaultRegion,
                'period' => $period,
                'language' => preg_match('/[а-яё]/iu', $phrase) ? 'ru' : 'unknown',
                'content_hash' => hash('sha256', $normalized),
                'status' => 'ready',
                'metadata' => ['source_line' => $index + 1],
            ];
        }

        return $result;
    }

    public function normalize(string $text): string
    {
        $text = Str::lower(trim($text));
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/https?:\/\/\S+/iu', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\s\-+]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public function redactPii(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', '[EMAIL]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(?:\+?7|8)[\s()\-]*\d{3}[\s()\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}(?!\d)/u', '[PHONE]', $text) ?? $text;
        $text = preg_replace('/\b\d{6}\s*,?\s*(?:г\.?\s*)?[А-ЯЁA-Z][^,\n]{2,80},\s*(?:ул\.?|улица|проспект|пр-т|пер\.?)\s+[^,\n]{2,80}/iu', '[ADDRESS]', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{0:string,1:?int,2:?string,3:?string}
     */
    private function parseLine(string $line): array
    {
        $delimiter = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : null);
        if (!$delimiter) {
            return [$line, null, null, null];
        }

        $columns = array_map('trim', str_getcsv($line, $delimiter));
        $phrase = (string) ($columns[0] ?? '');
        $frequency = isset($columns[1]) && preg_match('/^\d+$/', str_replace(' ', '', $columns[1]))
            ? (int) str_replace(' ', '', $columns[1])
            : null;

        return [
            $phrase,
            $frequency,
            $columns[2] ?? null,
            $columns[3] ?? null,
        ];
    }
}
