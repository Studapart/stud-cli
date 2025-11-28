<?php

declare(strict_types=1);

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
