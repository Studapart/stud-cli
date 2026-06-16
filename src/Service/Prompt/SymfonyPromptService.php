<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use App\DTO\MessageRef;
use App\Service\MessageRenderer;
use Symfony\Component\Console\Style\SymfonyStyle;

class SymfonyPromptService implements PromptInterface
{
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    public function ask(MessageRef|string $question, ?string $default = null, ?callable $validator = null): ?string
    {
        return $this->io->ask($this->render($question), $default, $validator);
    }

    public function askHidden(MessageRef|string $question, ?callable $validator = null): ?string
    {
        return $this->io->askHidden($this->render($question), $validator);
    }

    public function confirm(MessageRef|string $question, bool $default = true): bool
    {
        return $this->io->confirm($this->render($question), $default);
    }

    public function choice(MessageRef|string $question, array $choices, mixed $default = null, bool $multiSelect = false): mixed
    {
        return $this->io->choice($this->render($question), $choices, $default, $multiSelect);
    }

    private function render(MessageRef|string $question): string
    {
        return $this->messageRenderer?->render($question) ?? (string) $question;
    }
}
