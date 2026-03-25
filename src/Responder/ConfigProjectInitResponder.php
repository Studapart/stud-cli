<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ConfigProjectInitResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders config:project-init success (merged redacted config) or errors.
 */
class ConfigProjectInitResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
    ) {
    }

    public function respond(
        SymfonyStyle $io,
        ConfigProjectInitResponse $response,
        OutputFormat $format = OutputFormat::Cli,
    ): ?AgentJsonResponse {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        if (! $response->isSuccess()) {
            $message = $this->helper->translator->trans(
                $response->getError() ?? '',
                $response->getErrorParameters()
            );
            $this->logger->error(Logger::VERBOSITY_NORMAL, $message);

            return null;
        }

        $this->helper->initSection($this->logger, 'config.project_init.section_title');
        $statusKey = $response->updated ? 'config.project_init.status_updated' : 'config.project_init.status_unchanged';
        $this->logger->text(
            Logger::VERBOSITY_NORMAL,
            $this->helper->translator->trans($statusKey)
        );

        $rows = $this->buildDefinitionRows($response->redactedProjectConfig);
        if ($rows !== []) {
            $this->logger->definitionList(Logger::VERBOSITY_NORMAL, ...$rows);
        }

        return null;
    }

    protected function respondJson(ConfigProjectInitResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'config.project_init.failed');
        }

        return new AgentJsonResponse(true, data: [
            'updated' => $response->updated,
            'projectConfig' => $response->redactedProjectConfig,
        ]);
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
