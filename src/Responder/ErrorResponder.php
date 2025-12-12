<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ResponseInterface;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ErrorResponder handles all error Responses with consistent formatting.
 */
class ErrorResponder
{
    /**
     * @param array<string, string> $colors Color configuration (for future use in color formatting)
     */
    public function __construct(
        /** @phpstan-ignore-next-line - Translator property reserved for future i18n error messages */
        private readonly TranslationService $translator,
        /** @phpstan-ignore-next-line - Colors property reserved for future color formatting implementation */
        private readonly array $colors
    ) {
    }

    /**
     * Responds to an error Response by displaying the error message.
     */
    public function respond(SymfonyStyle $io, ResponseInterface $response): void
    {
        $error = $response->getError() ?? 'Unknown error';
        $io->error($error);
    }
}
