<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\ItemCreatePromptService;
use App\Service\Prompt\PromptInterface;

final class ItemCreatePrompts
{
    public function __construct(
        public readonly ItemCreatePromptService $service,
        public readonly PromptInterface $prompt,
    ) {
    }
}
