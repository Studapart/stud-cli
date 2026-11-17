<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\ConfigValidateIntegrationPing;
use App\DTO\MessageRef;
use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfigValidateResponse;
use App\Service\ConfigReadinessEvaluator;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders config:validate result: rollups, integrations, and legacy flat aliases.
 */
class ConfigValidateResponder
{
    private readonly ConfigValidateCliView $view;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger
    ) {
        $this->view = new ConfigValidateCliView($helper);
    }

    public function respond(
        SymfonyStyle $io,
        ConfigValidateResponse $response,
        OutputFormat $format = OutputFormat::Cli,
        bool $compact = true,
    ): ?AgentJsonResponse {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response, $compact);
        }

        if (! $response->isSuccess() && $response->getError() !== null && $response->integrations === []) {
            $message = $this->helper->translator->trans($response->getError());
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $message));

            return null;
        }

        $this->helper->initSection($this->logger, 'config.validate.section_title');
        $this->logger->definitionList(Logger::VERBOSITY_NORMAL, ...$this->view->rollupRows($response));

        $integrationRows = $this->view->integrationRows($response);
        if ($integrationRows !== []) {
            $this->logger->definitionList(Logger::VERBOSITY_NORMAL, ...$integrationRows);
        }

        foreach ($response->getNotices() as $notice) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->view->messageText($notice->message));
        }

        foreach ($response->getWarnings() as $warning) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->view->messageText($warning->message));
        }

        return null;
    }

    protected function respondJson(ConfigValidateResponse $response, bool $compact): AgentJsonResponse
    {
        $diagnostics = $response->diagnosticsPayload(new MessageRenderer($this->helper->translator, true));
        $fullData = $this->buildFullData($response);

        if (! $response->isSuccess()) {
            $error = $response->getError() ?? 'Validation failed';
            $data = $compact
                ? $this->compactFailureData($response)
                : $fullData;

            return new AgentJsonResponse(
                false,
                data: $data,
                error: $this->helper->translator->transForAgentText($error),
                diagnostics: $diagnostics,
            );
        }

        if ($response->globalRollup === ConfigReadinessEvaluator::ROLLUP_PARTIAL) {
            if ($compact) {
                return new AgentJsonResponse(
                    true,
                    data: ['rollups' => $fullData['rollups']],
                    diagnostics: $diagnostics,
                );
            }

            return new AgentJsonResponse(true, data: $fullData, diagnostics: $diagnostics);
        }

        if ($compact) {
            return AgentJsonResponse::successWithoutData($diagnostics);
        }

        return new AgentJsonResponse(true, data: $fullData, diagnostics: $diagnostics);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildFullData(ConfigValidateResponse $response): array
    {
        return [
            'globalRollup' => $response->globalRollup,
            'projectRollup' => $response->projectRollup,
            'rollups' => [
                'global' => $response->globalRollup,
                'project' => $response->projectRollup,
            ],
            'integrations' => $this->integrationsForAgent($response->integrations),
            'jiraStatus' => $response->jiraStatus,
            'jiraMessage' => $this->formatMessageForAgent($response->jiraMessage),
            'gitStatus' => $response->gitStatus,
            'gitMessage' => $this->formatMessageForAgent($response->gitMessage),
            'linearStatus' => $response->linearStatus,
            'linearMessage' => $this->formatMessageForAgent($response->linearMessage),
            'gitProvider' => $response->gitProvider,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function compactFailureData(ConfigValidateResponse $response): array
    {
        return [
            'globalRollup' => $response->globalRollup,
            'projectRollup' => $response->projectRollup,
            'rollups' => [
                'global' => $response->globalRollup,
                'project' => $response->projectRollup,
            ],
            'integrations' => $this->integrationsForAgent($response->integrations),
        ];
    }

    /**
     * @param array<string, ConfigValidateIntegrationPing> $integrations
     *
     * @return array<string, array{status: string, message: string|null}>
     */
    protected function integrationsForAgent(array $integrations): array
    {
        $out = [];
        foreach ($integrations as $key => $ping) {
            $out[$key] = [
                'status' => $ping->status,
                'message' => $this->formatMessageForAgent($ping->message),
            ];
        }

        return $out;
    }

    protected function formatMessageForAgent(MessageRef|string|null $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if ($message instanceof MessageRef) {
            return $this->helper->translator->transForAgentText($message->key, $message->parameters);
        }

        return $message;
    }
}
