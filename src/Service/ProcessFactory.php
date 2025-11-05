<?php

namespace App\Service;

use Symfony\Component\Process\Process;

/**
 * @codeCoverageIgnore
 */
class ProcessFactory
{
    public function create(string $command): Process
    {
        return Process::fromShellCommandline($command);
    }
}
