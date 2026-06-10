<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use App\DTO\MessageRef;

final class NonInteractivePromptService implements PromptInterface
{
    public function ask(MessageRef|string $question, ?string $default = null, ?callable $validator = null): ?string
    {
        if ($validator !== null && $default !== null) {
            return $validator($default);
        }

        return $default;
    }

    public function askHidden(MessageRef|string $question, ?callable $validator = null): ?string
    {
        return null;
    }

    public function confirm(MessageRef|string $question, bool $default = true): bool
    {
        return $default;
    }

    public function choice(MessageRef|string $question, array $choices, mixed $default = null, bool $multiSelect = false): mixed
    {
        if ($default !== null) {
            return $default;
        }

        return $multiSelect ? [] : ($choices[0] ?? null);
    }
}
