<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
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
                return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
            }
            $data = $response->isSingleKey()
                ? ['singleKey' => $response->singleKey, 'singleKeyValue' => $response->singleKeyValue, 'singleKeySection' => $response->singleKeySection]
                : ['globalConfig' => $response->globalConfig, 'projectConfig' => $response->projectConfig];

            return new AgentJsonResponse(true, data: $data);
        }

        if (! $response->isSuccess()) {
            $message = $this->helper->translator->trans($response->getError() ?? '', $response->getErrorParameters());
            $io->error($message);

            return null;
        }

        if ($response->isSingleKey()) {
            $this->respondSingleKey($io, $response, $quiet);

            return null;
        }

        $this->renderSection($io, 'config.show.section_global', $response->globalConfig);

        if ($response->projectConfig !== null && $response->projectConfig !== []) {
            $this->renderSection($io, 'config.show.section_project', $response->projectConfig);
        }

        return null;
    }

    protected function respondSingleKey(SymfonyStyle $io, ConfigShowResponse $response, bool $quiet): void
    {
        if ($quiet) {
            $this->logger->rawValue($this->formatValue($response->singleKeyValue));

            return;
        }

        $sectionKey = $response->singleKeySection === 'project' ? 'config.show.section_project' : 'config.show.section_global';
        $this->helper->initSection($io, $sectionKey);

        $valueStr = $this->formatValue($response->singleKeyValue);
        $label = $response->singleKey ?? '';
        if ($this->helper->colorHelper !== null) {
            $label = $this->helper->colorHelper->format('definition_key', $label);
            $valueStr = $this->helper->colorHelper->format('definition_value', $valueStr);
        }
        $io->definitionList([$label => $valueStr]);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function renderSection(SymfonyStyle $io, string $sectionTitleKey, array $config): void
    {
        $this->helper->initSection($io, $sectionTitleKey);

        $rows = $this->buildDefinitionRows($config);
        if ($rows !== []) {
            $io->definitionList(...$rows);
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
}
