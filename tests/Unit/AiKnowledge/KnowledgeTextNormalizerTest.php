<?php

namespace Tests\Unit\AiKnowledge;

use App\Services\AiKnowledge\KnowledgeTextNormalizer;
use PHPUnit\Framework\TestCase;

class KnowledgeTextNormalizerTest extends TestCase
{
    public function test_it_parses_plain_and_wordstat_lines(): void
    {
        $normalizer = new KnowledgeTextNormalizer();

        $rows = $normalizer->parse(
            "Течёт бачок\nремонт унитаза;1 250;Москва;2026-07",
            'Россия'
        );

        $this->assertCount(2, $rows);
        $this->assertSame('течет бачок', $rows[0]['normalized_text']);
        $this->assertNull($rows[0]['frequency']);
        $this->assertSame('Россия', $rows[0]['region']);
        $this->assertSame(1250, $rows[1]['frequency']);
        $this->assertSame('Москва', $rows[1]['region']);
        $this->assertSame('2026-07', $rows[1]['period']);
    }

    public function test_it_redacts_contacts_before_ai_processing(): void
    {
        $normalizer = new KnowledgeTextNormalizer();

        $redacted = $normalizer->redactPii(
            'Позвоните +7 (999) 123-45-67 или test@example.com'
        );

        $this->assertSame('Позвоните [PHONE] или [EMAIL]', $redacted);
    }
}
