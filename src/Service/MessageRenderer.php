<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

final class MessageRenderer
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly bool $agent = false,
    ) {
    }

    public function render(MessageRef|string|null $message, ?string $domain = null, ?string $locale = null): ?string
    {
        if ($message === null) {
            return null;
        }

        if (is_string($message)) {
            return $message;
        }

        if ($this->agent) {
            return $this->translator->renderForAgent($message);
        }

        return $this->translator->render($message, $domain, $locale);
    }

    public function renderForAgent(MessageRef|string|null $message): ?string
    {
        return $this->translator->renderForAgent($message);
    }
}
