<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProcessFactoryTest extends TestCase
{
    public function testCreateSetsGitSubprocessTimeout(): void
    {
        $factory = new ProcessFactory();
        $process = $factory->create('echo test');

        $this->assertSame(ProcessFactory::GIT_SUBPROCESS_TIMEOUT_SECONDS, $process->getTimeout());
    }

    public function testCreateSetsGitTerminalPromptToZero(): void
    {
        $factory = new ProcessFactory();
        $process = $factory->create('echo test');

        $this->assertSame('0', $process->getEnv()['GIT_TERMINAL_PROMPT']);
    }

    public function testCreateAllowsDisablingTimeout(): void
    {
        $factory = new ProcessFactory();
        $process = $factory->create('echo test', null);

        $this->assertNull($process->getTimeout());
    }

    public function testCreateRunsCommandSuccessfully(): void
    {
        $factory = new ProcessFactory();
        $process = $factory->create('echo hello');

        $process->mustRun();

        $this->assertSame("hello\n", $process->getOutput());
        $this->assertInstanceOf(Process::class, $process);
    }
}
