<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\StudHelpCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class StudHelpCommandTest extends TestCase
{
    public function testNativeHelpDelegatesToSymfonyHelpCommand(): void
    {
        $command = new StudHelpCommand();
        $command->setCommand(new Command('demo'));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Usage:', $tester->getDisplay());
        self::assertStringContainsString('demo', $tester->getDisplay());
    }

    public function testExplicitCommandHelpDelegatesToStudHelpTask(): void
    {
        $command = new TestableStudHelpCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['command_name' => 'commit']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('commit', $command->delegatedCommandName);
        self::assertFalse($command->delegatedAgent);
    }

    public function testAgentHelpDelegatesWithoutDefaultCommandName(): void
    {
        $command = new TestableStudHelpCommand();
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--agent' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNull($command->delegatedCommandName);
        self::assertTrue($command->delegatedAgent);
    }
}

class TestableStudHelpCommand extends StudHelpCommand
{
    public ?string $delegatedCommandName = null;

    public bool $delegatedAgent = false;

    /**
     * Capture explicit help routing without invoking the global Castor task.
     */
    protected function runStudHelp(?string $commandName, bool $agent): int
    {
        $this->delegatedCommandName = $commandName;
        $this->delegatedAgent = $agent;

        return Command::SUCCESS;
    }
}
