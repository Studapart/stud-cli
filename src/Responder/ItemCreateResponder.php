<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemCreateResponse;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemCreateResponder
{
    /**
     * @param array<string, string> $jiraConfig Must contain JIRA_URL
     */
    public function __construct(
        private readonly TranslationService $translator,
        private readonly array $jiraConfig,
        private readonly Logger $logger
    ) {
    }

    public function respond(SymfonyStyle $io, ItemCreateResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }

            return new AgentJsonResponse(true, data: [
                'key' => $response->key,
                'self' => $response->self,
                'skippedOptionalFields' => $response->skippedOptionalFields,
            ]);
        }

        if (! $response->isSuccess() || $response->key === null) {
            return null;
        }
        $baseUrl = rtrim($this->jiraConfig['JIRA_URL'] ?? '', '/');
        $url = $baseUrl !== '' ? $baseUrl . '/browse/' . $response->key : $response->self;
        $message = $this->translator->trans('item.create.success', [
            'key' => $response->key,
            'url' => $url,
        ]);
        $this->logger->success(Logger::VERBOSITY_NORMAL, $message);
        $skipped = $response->skippedOptionalFields ?? [];
        if ($skipped !== []) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.create.note_skipped_optional_fields', ['%fields%' => implode(', ', $skipped)]));
        }

        return null;
    }
}
