<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

class SubmitLabelResolver
{
    public function __construct(
        private readonly GitProviderInterface $gitProvider,
        private readonly TranslationService $translator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, string>|null
     */
    public function validateAndProcessLabels(string $labelsInput, bool $quiet = false): ?array
    {
        $requestedLabels = $this->parseLabelInput($labelsInput);
        if ($requestedLabels === []) {
            return [];
        }

        $remoteLabels = $this->fetchRemoteLabels();
        if ($remoteLabels === null) {
            return null;
        }

        $existingLabelsMap = $this->buildExistingLabelsMap($remoteLabels);
        [$finalLabels, $unknownLabels] = $this->partitionKnownAndUnknownLabels($requestedLabels, $existingLabelsMap);

        foreach ($unknownLabels as $unknownLabel) {
            $result = $this->resolveUnknownLabel($unknownLabel, $quiet, $finalLabels);
            if ($result === null) {
                return null;
            }
            $finalLabels = $result;
        }

        return $finalLabels;
    }

    /**
     * @return array<int, string>
     */
    public function parseLabelInput(string $labelsInput): array
    {
        $requestedLabels = array_map('trim', explode(',', $labelsInput));
        $requestedLabels = array_filter($requestedLabels, fn (string $label): bool => $label !== '');

        return array_values($requestedLabels);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function fetchRemoteLabels(): ?array
    {
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.fetching_labels'));

        try {
            return $this->gitProvider->getLabels();
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('submit.error_fetch_labels', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_fetch_labels', ['error' => $e->getMessage()])));

            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $remoteLabels
     * @return array<string, string>
     */
    public function buildExistingLabelsMap(array $remoteLabels): array
    {
        $map = [];
        foreach ($remoteLabels as $label) {
            $name = isset($label['name']) && is_string($label['name']) ? $label['name'] : '';
            if ($name !== '') {
                $map[strtolower($name)] = $name;
            }
        }

        return $map;
    }

    /**
     * @param array<string> $requestedLabels
     * @param array<string, string> $existingLabelsMap
     *
     * @return array{array<int, string>, array<int, string>}
     */
    public function partitionKnownAndUnknownLabels(array $requestedLabels, array $existingLabelsMap): array
    {
        $finalLabels = [];
        $unknownLabels = [];
        foreach ($requestedLabels as $requestedLabel) {
            $normalized = strtolower($requestedLabel);
            if (isset($existingLabelsMap[$normalized])) {
                $finalLabels[] = $existingLabelsMap[$normalized];
            } else {
                $unknownLabels[] = $requestedLabel;
            }
        }

        return [$finalLabels, $unknownLabels];
    }

    /**
     * @param array<int, string> $finalLabels
     *
     * @return array<int, string>|null
     */
    public function resolveUnknownLabel(string $unknownLabel, bool $quiet, array $finalLabels): ?array
    {
        if ($quiet) {
            return $finalLabels;
        }

        $choice = $this->logger->choice(
            $this->translator->trans('submit.label_unknown_prompt', ['label' => $unknownLabel]),
            [
                $this->translator->trans('submit.label_create_option'),
                $this->translator->trans('submit.label_ignore_option'),
                $this->translator->trans('submit.label_retry_option'),
            ],
            0
        );

        if ($choice === $this->translator->trans('submit.label_retry_option')) {
            return null;
        }

        if ($choice === $this->translator->trans('submit.label_create_option')) {
            $created = $this->createLabelOnProvider($unknownLabel);

            return $created === null ? null : array_merge($finalLabels, [$created]);
        }

        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('submit.label_ignored', ['label' => $unknownLabel])}</>");

        return $finalLabels;
    }

    public function createLabelOnProvider(string $unknownLabel): ?string
    {
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.label_creating', ['label' => $unknownLabel]));

        try {
            $color = sprintf('%06x', mt_rand(0, 0xffffff));
            $this->gitProvider->createLabel($unknownLabel, $color);
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.label_created', ['label' => $unknownLabel]));

            return $unknownLabel;
        } catch (ApiException $e) {
            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()])));

            return null;
        }
    }
}
