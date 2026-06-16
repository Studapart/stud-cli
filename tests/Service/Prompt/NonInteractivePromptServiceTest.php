<?php

declare(strict_types=1);

namespace App\Tests\Service\Prompt;

use App\Service\Prompt\NonInteractivePromptService;
use PHPUnit\Framework\TestCase;

final class NonInteractivePromptServiceTest extends TestCase
{
    public function testReturnsDefaultsWithoutInteraction(): void
    {
        $prompt = new NonInteractivePromptService();

        $this->assertSame('default', $prompt->ask('Question?', 'default'));
        $this->assertNull($prompt->askHidden('Secret?'));
        $this->assertFalse($prompt->confirm('Continue?', false));
        $this->assertSame('b', $prompt->choice('Pick', ['a', 'b'], 'b'));
        $this->assertSame('a', $prompt->choice('Pick', ['a', 'b']));
        $this->assertSame([], $prompt->choice('Pick', ['a', 'b'], multiSelect: true));
    }

    public function testAskAppliesValidatorToDefault(): void
    {
        $prompt = new NonInteractivePromptService();

        $this->assertSame('DEFAULT', $prompt->ask('Question?', 'default', strtoupper(...)));
    }
}
