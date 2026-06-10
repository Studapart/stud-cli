<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Exception\ApiException;

class SubmitLabelResolver
{
    private const LABEL_CREATE_OPTION = 'Create: Create the label on GitHub and add it to the PR';
    private const LABEL_IGNORE_OPTION = 'Ignore: Skip this label and remove it from the final list';
    private const LABEL_RETRY_OPTION = 'Retry: Abort the command and re-run with a corrected list';

    public function __construct(
        private readonly GitProviderInterface $gitProvider,
        mixed $translator,
        private readonly WorkflowOutput $logger,
    ) {
        unset($translator);
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
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('submit.fetching_labels'));

        try {
            return $this->gitProvider->getLabels();
        } catch (ApiException $e) {
            $this->logger->addErrorWithDetails(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('submit.error_fetch_labels', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('submit.error_fetch_labels', ['error' => $e->getMessage()]));

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
            MessageRef::key('submit.label_unknown_prompt', ['label' => $unknownLabel]),
            [
                self::LABEL_CREATE_OPTION,
                self::LABEL_IGNORE_OPTION,
                self::LABEL_RETRY_OPTION,
            ],
            0
        );

        if ($choice === self::LABEL_RETRY_OPTION) {
            return null;
        }

        if ($choice === self::LABEL_CREATE_OPTION) {
            $created = $this->createLabelOnProvider($unknownLabel);

            return $created === null ? null : array_merge($finalLabels, [$created]);
        }

        $this->logger->addLine(WorkflowOutput::VERBOSITY_VERBOSE, MessageRef::key('submit.label_ignored', ['label' => $unknownLabel]));

        return $finalLabels;
    }

    public function createLabelOnProvider(string $unknownLabel): ?string
    {
        $this->logger->addText(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('submit.label_creating', ['label' => $unknownLabel]));

        try {
            $color = sprintf('%06x', mt_rand(0, 0xffffff));
            $this->gitProvider->createLabel($unknownLabel, $color);
            $this->logger->addSuccess(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('submit.label_created', ['label' => $unknownLabel]));

            return $unknownLabel;
        } catch (ApiException $e) {
            $this->logger->addErrorWithDetails(
                WorkflowOutput::VERBOSITY_NORMAL,
                MessageRef::key('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $this->logger->addError(WorkflowOutput::VERBOSITY_NORMAL, MessageRef::key('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()]));

            return null;
        }
    }
}
