<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use App\DTO\MessageRef;

interface PromptInterface
{
    public function ask(MessageRef|string $question, ?string $default = null, ?callable $validator = null): ?string;

    public function askHidden(MessageRef|string $question, ?callable $validator = null): ?string;

    public function confirm(MessageRef|string $question, bool $default = true): bool;

    /**
     * @param array<string> $choices
     */
    public function choice(MessageRef|string $question, array $choices, mixed $default = null, bool $multiSelect = false): mixed;
}
