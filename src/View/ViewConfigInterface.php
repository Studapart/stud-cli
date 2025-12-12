<?php

declare(strict_types=1);

namespace App\View;

use Symfony\Component\Console\Style\SymfonyStyle;

interface ViewConfigInterface
{
    /**
     * @param array<int, mixed> $dtos
     * @param array<string, mixed> $context
     */
    public function render(array $dtos, SymfonyStyle $io, array $context = []): void;

    public function getType(): string;
}
