<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Enum\ResponseMessageLevel;
use App\Response\AgentJsonResponse;
use App\Response\ConfigShowResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigShowResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
    ) {
    }

    public function respond(SymfonyStyle $io, ConfigShowResponse $response, bool $quiet = false, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            if (! $response->isSuccess()) {
                $error = $response->getError() ?? 'Unknown error';

                return new AgentJsonResponse(false, error: $this->helper->translator->transForAgentText($error, $response->getErrorParameters()));
            }
            $data = $response->isSingleKey()
                ? ['singleKey' => $response->singleKey, 'singleKeyValue' => $response->singleKeyValue, 'singleKeySection' => $response->singleKeySection]
                : ['globalConfig' => $response->globalConfig, 'projectConfig' => $response->projectConfig];

            return new AgentJsonResponse(true, data: $data, diagnostics: $response->diagnosticsPayload());
        }

        if (! $response->isSuccess()) {
            $message = $this->helper->translator->trans($response->getError() ?? '', $response->getErrorParameters());
            $this->logger->error(Logger::VERBOSITY_NORMAL, $message);

            return null;
        }

        $this->renderDiagnostics($response);

        if ($response->isSingleKey()) {
            $this->respondSingleKey($response, $quiet);

            return null;
        }

        $this->renderSection('config.show.section_global', $response->globalConfig);

        if ($response->projectConfig !== null && $response->projectConfig !== []) {
            $this->renderSection('config.show.section_project', $response->projectConfig);
        }

        return null;
    }

    protected function respondSingleKey(ConfigShowResponse $response, bool $quiet): void
    {
        if ($quiet) {
            $this->logger->rawValue($this->formatValue($response->singleKeyValue));

            return;
        }

        $sectionKey = $response->singleKeySection === 'project' ? 'config.show.section_project' : 'config.show.section_global';
        $this->helper->initSection($this->logger, $sectionKey);

        $valueStr = $this->formatValue($response->singleKeyValue);
        $label = $response->singleKey ?? '';
        if ($this->helper->colorHelper !== null) {
            $label = $this->helper->colorHelper->format('definition_key', $label);
            $valueStr = $this->helper->colorHelper->format('definition_value', $valueStr);
        }
        $this->logger->definitionList(Logger::VERBOSITY_NORMAL, [$label => $valueStr]);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function renderSection(string $sectionTitleKey, array $config): void
    {
        $this->helper->initSection($this->logger, $sectionTitleKey);

        $rows = $this->buildDefinitionRows($config);
        if ($rows !== []) {
            $this->logger->definitionList(Logger::VERBOSITY_NORMAL, ...$rows);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, string>>
     */
    protected function buildDefinitionRows(array $config): array
    {
        $rows = [];
        $keys = array_keys($config);
        sort($keys);

        foreach ($keys as $key) {
            $value = $config[$key];
            $valueStr = $this->formatValue($value);
            $label = $key;
            if ($this->helper->colorHelper !== null) {
                $label = $this->helper->colorHelper->format('definition_key', $label);
                $valueStr = $this->helper->colorHelper->format('definition_value', $valueStr);
            }
            $rows[] = [$label => $valueStr];
        }

        return $rows;
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return '';
    }

    private function renderDiagnostics(ConfigShowResponse $response): void
    {
        foreach ($response->getMessages() as $message) {
            $this->renderDiagnosticMessage($message);
        }
    }

    private function renderDiagnosticMessage(ResponseMessage $message): void
    {
        $text = $this->renderMessage($message->message);

        match ($message->level) {
            ResponseMessageLevel::Error => $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $text,
                $message->technicalDetails ?? '',
            ),
            ResponseMessageLevel::Warning => $this->logger->warning(Logger::VERBOSITY_NORMAL, $text),
            ResponseMessageLevel::Notice => $this->logger->note(Logger::VERBOSITY_NORMAL, $text),
            ResponseMessageLevel::Info => $this->logger->text(Logger::VERBOSITY_VERBOSE, $text),
        };

        if ($message->technicalDetails !== null && $message->technicalDetails !== '') {
            $this->logger->text(Logger::VERBOSITY_DEBUG, ' Technical details: ' . $message->technicalDetails);
        }
    }

    private function renderMessage(MessageRef|string $message): string
    {
        if ($message instanceof MessageRef) {
            return $this->helper->translator->trans($message->key, $message->parameters);
        }

        return $message;
    }
}
