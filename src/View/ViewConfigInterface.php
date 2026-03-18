<?php

declare(strict_types=1);

namespace App\View;

use App\Service\Logger;

interface ViewConfigInterface
{
    /**
     * @param array<int, mixed> $dtos
     * @param array<string, mixed> $context
     */
    public function render(array $dtos, Logger $logger, array $context = []): void;

    public function getType(): string;
}
