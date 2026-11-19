<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OutputVerbosityGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;

class OutputVerbosityGateTest extends TestCase
{
    /**
     * @return array<string, array{bool, bool, bool, int, bool}>
     */
    public static function verbosityLevels(): array
    {
        return [
            'normal allows normal' => [false, false, false, OutputVerbosityGate::VERBOSITY_NORMAL, true],
            'normal hides verbose' => [false, false, false, OutputVerbosityGate::VERBOSITY_VERBOSE, false],
            'verbose allows verbose' => [false, false, true, OutputVerbosityGate::VERBOSITY_VERBOSE, true],
            'verbose hides very verbose' => [false, false, true, OutputVerbosityGate::VERBOSITY_VERY_VERBOSE, false],
            'very verbose allows very verbose' => [false, true, true, OutputVerbosityGate::VERBOSITY_VERY_VERBOSE, true],
            'very verbose hides debug' => [false, true, true, OutputVerbosityGate::VERBOSITY_DEBUG, false],
            'debug allows debug' => [true, true, true, OutputVerbosityGate::VERBOSITY_DEBUG, true],
        ];
    }

    #[DataProvider('verbosityLevels')]
    public function testAllowsComparesRequestedVerbosityWithCurrentLevel(
        bool $isDebug,
        bool $isVeryVerbose,
        bool $isVerbose,
        int $verbosity,
        bool $expected,
    ): void {
        $io = $this->createStub(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn($isDebug);
        $io->method('isVeryVerbose')->willReturn($isVeryVerbose);
        $io->method('isVerbose')->willReturn($isVerbose);

        $this->assertSame($expected, (new OutputVerbosityGate($io))->allows($verbosity));
    }

    public function testAllowsIsFalseWhenQuiet(): void
    {
        $io = $this->createStub(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $this->assertFalse((new OutputVerbosityGate($io))->allows(OutputVerbosityGate::VERBOSITY_NORMAL));
    }

    public function testAllowsEssentialIsTrueWhenQuiet(): void
    {
        $io = $this->createStub(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(true);

        $this->assertTrue((new OutputVerbosityGate($io))->allowsEssential(OutputVerbosityGate::VERBOSITY_NORMAL));
    }

    public function testAllowsEssentialFollowsVerbosityWhenNotQuiet(): void
    {
        $io = $this->createStub(SymfonyStyle::class);
        $io->method('isQuiet')->willReturn(false);
        $io->method('isDebug')->willReturn(false);
        $io->method('isVeryVerbose')->willReturn(false);
        $io->method('isVerbose')->willReturn(false);
        $gate = new OutputVerbosityGate($io);

        $this->assertTrue($gate->allowsEssential(OutputVerbosityGate::VERBOSITY_NORMAL));
        $this->assertFalse($gate->allowsEssential(OutputVerbosityGate::VERBOSITY_VERBOSE));
    }

    public function testSuppressOutputBlocksAllOutput(): void
    {
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->never())->method('isQuiet');
        $gate = new OutputVerbosityGate($io, true);

        $this->assertFalse($gate->allows(OutputVerbosityGate::VERBOSITY_NORMAL));
        $this->assertFalse($gate->allowsEssential(OutputVerbosityGate::VERBOSITY_NORMAL));
    }
}
