<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class ProcessFactory
{
    public const GIT_SUBPROCESS_TIMEOUT_SECONDS = 600.0;

    public function create(string $command, ?float $timeout = self::GIT_SUBPROCESS_TIMEOUT_SECONDS): Process
    {
        $env = $_ENV;
        $env['GIT_TERMINAL_PROMPT'] = '0';

        $process = Process::fromShellCommandline($command, null, $env);
        $process->setTimeout($timeout);

        return $process;
    }
}
