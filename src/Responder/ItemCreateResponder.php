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
                return new AgentJsonResponse(false, error: $this->translator->renderForAgentText($response->getErrorMessage()));
            }

            return new AgentJsonResponse(true, data: [
                'key' => $response->key,
                'self' => $response->self,
                'skippedOptionalFields' => $this->renderSkippedFields($response->skippedOptionalFields ?? [], agent: true),
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
        $skipped = $this->renderSkippedFields($response->skippedOptionalFields ?? []);
        if ($skipped !== []) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('item.create.note_skipped_optional_fields', ['%fields%' => implode(', ', $skipped)]));
        }

        return null;
    }

    /**
     * @param array<int, mixed> $skippedFields
     * @return list<string>
     */
    private function renderSkippedFields(array $skippedFields, bool $agent = false): array
    {
        return array_values(array_map(
            fn (mixed $field): string => $this->renderSkippedField($field, $agent),
            $skippedFields
        ));
    }

    private function renderSkippedField(mixed $field, bool $agent): string
    {
        $message = is_string($field) || $field instanceof \App\DTO\MessageRef ? $field : (string) $field;

        return $agent ? $this->translator->renderForAgentText($message) : $this->translator->renderText($message);
    }
}
