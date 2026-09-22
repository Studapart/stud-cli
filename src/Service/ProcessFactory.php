<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class ProcessFactory
{
    public const GIT_SUBPROCESS_TIMEOUT_SECONDS = 600.0;

    /**
     * @param array<int, string> $command
     */
    public function create(array $command, ?float $timeout = self::GIT_SUBPROCESS_TIMEOUT_SECONDS): Process
    {
        $env = $_ENV;
        $env['GIT_TERMINAL_PROMPT'] = '0';

        $process = new Process($command, null, $env);
        $process->setTimeout($timeout);

        return $process;
    }
}
