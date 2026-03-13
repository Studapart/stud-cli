<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Minimal I/O for agent mode (testing). Production uses stdin/stdout when not injected.
 */
interface AgentModeIoInterface
{
    public function getContents(): string;

    public function write(string $data): void;
}
