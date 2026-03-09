<?php

declare(strict_types=1);

namespace App\Responder;

use App\Response\ConfigShowResponse;
use App\Service\ColorHelper;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigShowResponder
{
    public function __construct(
        private readonly TranslationService $translator,
        private readonly Logger $logger,
        private readonly ?ColorHelper $colorHelper = null
    ) {
    }

    public function respond(SymfonyStyle $io, ConfigShowResponse $response, bool $quiet = false): void
    {
        if ($this->colorHelper !== null) {
            $this->colorHelper->registerStyles($io);
        }

        if (! $response->isSuccess()) {
            $message = $this->translator->trans($response->getError() ?? '', $response->getErrorParameters());
            $io->error($message);

            return;
        }

        if ($response->isSingleKey()) {
            $this->respondSingleKey($io, $response, $quiet);

            return;
        }

        $this->renderSection($io, 'config.show.section_global', $response->globalConfig);

        if ($response->projectConfig !== null && $response->projectConfig !== []) {
            $this->renderSection($io, 'config.show.section_project', $response->projectConfig);
        }
    }

    protected function respondSingleKey(SymfonyStyle $io, ConfigShowResponse $response, bool $quiet): void
    {
        if ($quiet) {
            $this->logger->rawValue($this->formatValue($response->singleKeyValue));

            return;
        }

        $sectionKey = $response->singleKeySection === 'project' ? 'config.show.section_project' : 'config.show.section_global';
        $sectionTitle = $this->translator->trans($sectionKey);
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

        $valueStr = $this->formatValue($response->singleKeyValue);
        $label = $response->singleKey ?? '';
        if ($this->colorHelper !== null) {
            $label = $this->colorHelper->format('definition_key', $label);
            $valueStr = $this->colorHelper->format('definition_value', $valueStr);
        }
        $io->definitionList([$label => $valueStr]);
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function renderSection(SymfonyStyle $io, string $sectionTitleKey, array $config): void
    {
        $sectionTitle = $this->translator->trans($sectionTitleKey);
        if ($this->colorHelper !== null) {
            $sectionTitle = $this->colorHelper->format('section_title', $sectionTitle);
        }
        $io->section($sectionTitle);

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
            if ($this->colorHelper !== null) {
                $label = $this->colorHelper->format('definition_key', $label);
                $valueStr = $this->colorHelper->format('definition_value', $valueStr);
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
