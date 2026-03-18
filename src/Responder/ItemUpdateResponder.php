<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemUpdateResponse;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemUpdateResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function respond(SymfonyStyle $io, ItemUpdateResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }

            return new AgentJsonResponse(true, data: [
                'key' => $response->key,
                'skippedOptionalFields' => $response->skippedOptionalFields,
            ]);
        }

        if (! $response->isSuccess() || $response->key === null) {
            return null;
        }
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.update.success', ['key' => $response->key]));
        $skipped = $response->skippedOptionalFields ?? [];
        if ($skipped !== []) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.update.note_skipped_optional_fields', ['%fields%' => implode(', ', $skipped)]));
        }

        return null;
    }
}
