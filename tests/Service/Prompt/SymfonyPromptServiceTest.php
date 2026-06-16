<?php

declare(strict_types=1);

namespace App\Tests\Service\Prompt;

use App\Service\Prompt\SymfonyPromptService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SymfonyPromptServiceTest extends TestCase
{
    public function testDelegatesToSymfonyStyle(): void
    {
        $validator = static fn (?string $value): ?string => $value;
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('ask')->with('Question?', 'default', $validator)->willReturn('answer');
        $io->expects($this->once())->method('askHidden')->with('Secret?', $validator)->willReturn('secret');
        $io->expects($this->once())->method('confirm')->with('Continue?', false)->willReturn(true);
        $io->expects($this->once())->method('choice')->with('Pick', ['a', 'b'], 'b', true)->willReturn(['b']);

        $prompt = new SymfonyPromptService($io);

        $this->assertSame('answer', $prompt->ask('Question?', 'default', $validator));
        $this->assertSame('secret', $prompt->askHidden('Secret?', $validator));
        $this->assertTrue($prompt->confirm('Continue?', false));
        $this->assertSame(['b'], $prompt->choice('Pick', ['a', 'b'], 'b', true));
    }
}
