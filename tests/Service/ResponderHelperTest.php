<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ColorHelper;
use App\Service\ResponderHelper;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ResponderHelperTest extends TestCase
{
    private TranslationService $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(
            fn (string $key, array $params = []): string => $key . ($params !== [] ? ' ' . json_encode($params) : '')
        );
    }

    public function testInitSectionWithoutColorHelper(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle();

        $helper->initSection($io, 'test.section');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('test.section', $output);
    }

    public function testInitSectionWithColorHelper(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->expects($this->once())->method('registerStyles');
        $colorHelper->expects($this->once())->method('format')
            ->with('section_title', 'test.section')
            ->willReturn('<section_title>test.section</>');

        $helper = new ResponderHelper($this->translator, $colorHelper);
        $io = $this->createSymfonyStyle();

        $helper->initSection($io, 'test.section');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('test.section', $output);
    }

    public function testInitSectionWithParams(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle();

        $helper->initSection($io, 'test.section', ['key' => 'value']);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('test.section', $output);
        $this->assertStringContainsString('value', $output);
    }

    public function testVerboseCommentWhenVerbose(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_VERBOSE);

        $helper->verboseComment($io, 'test.verbose');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('test.verbose', $output);
    }

    public function testVerboseCommentWhenNotVerbose(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_NORMAL);

        $helper->verboseComment($io, 'test.verbose');

        $output = $this->getOutput($io);
        $this->assertStringNotContainsString('test.verbose', $output);
    }

    public function testVerboseCommentWithColorHelper(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->method('format')
            ->with('comment', 'test.verbose')
            ->willReturn('[COMMENT]test.verbose');

        $helper = new ResponderHelper($this->translator, $colorHelper);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_VERBOSE);

        $helper->verboseComment($io, 'test.verbose');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('[COMMENT]test.verbose', $output);
    }

    public function testVerboseCommentWithParams(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_VERBOSE);

        $helper->verboseComment($io, 'test.msg', ['jql' => 'SELECT *']);

        $output = $this->getOutput($io);
        $this->assertStringContainsString('SELECT *', $output);
    }

    public function testVerboseNoteWhenVerbose(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_VERBOSE);

        $helper->verboseNote($io, 'test.note');

        $output = $this->getOutput($io);
        $this->assertStringContainsString('test.note', $output);
    }

    public function testVerboseNoteWhenNotVerbose(): void
    {
        $helper = new ResponderHelper($this->translator);
        $io = $this->createSymfonyStyle(OutputInterface::VERBOSITY_NORMAL);

        $helper->verboseNote($io, 'test.note');

        $output = $this->getOutput($io);
        $this->assertStringNotContainsString('test.note', $output);
    }

    public function testFormatCommentWithoutColorHelper(): void
    {
        $helper = new ResponderHelper($this->translator);
        $result = $helper->formatComment('hello');
        $this->assertSame('<fg=gray>hello</>', $result);
    }

    public function testFormatCommentWithColorHelper(): void
    {
        $colorHelper = $this->createMock(ColorHelper::class);
        $colorHelper->method('format')
            ->with('comment', 'hello')
            ->willReturn('<comment>hello</>');

        $helper = new ResponderHelper($this->translator, $colorHelper);
        $result = $helper->formatComment('hello');
        $this->assertSame('<comment>hello</>', $result);
    }

    private function createSymfonyStyle(int $verbosity = OutputInterface::VERBOSITY_NORMAL): SymfonyStyle
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput($verbosity);

        return new SymfonyStyle($input, $output);
    }

    private function getOutput(SymfonyStyle $io): string
    {
        $reflection = new \ReflectionClass($io);
        $property = $reflection->getProperty('output');
        $property->setAccessible(true);
        /** @var BufferedOutput $output */
        $output = $property->getValue($io);

        return $output->fetch();
    }
}
