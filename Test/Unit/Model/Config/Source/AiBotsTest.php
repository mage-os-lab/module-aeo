<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Config\Source;

use MageOS\Aeo\Model\Config\Source\AiBots;
use PHPUnit\Framework\TestCase;

class AiBotsTest extends TestCase
{
    public function testIncludesMajorAiUserAgents(): void
    {
        $values = array_column((new AiBots())->toOptionArray(), 'value');
        $this->assertContains('GPTBot', $values);
        $this->assertContains('ClaudeBot', $values);
        $this->assertContains('CCBot', $values);
        $this->assertContains('Bytespider', $values);
        $this->assertContains('Google-Extended', $values);
    }

    public function testEachOptionHasNonEmptyValueAndLabel(): void
    {
        foreach ((new AiBots())->toOptionArray() as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertNotSame('', (string) $option['value']);
            $this->assertNotSame('', (string) $option['label']);
        }
    }

    public function testValuesAreUnique(): void
    {
        $values = array_column((new AiBots())->toOptionArray(), 'value');
        $this->assertSame(array_values(array_unique($values)), $values);
    }
}
