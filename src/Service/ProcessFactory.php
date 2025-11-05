<?php

namespace App\Service;

use Symfony\Component\Process\Process;

class ProcessFactory
{
    public function create(string $command): Process
    {
        return Process::fromShellCommandline($command);
    }
}
