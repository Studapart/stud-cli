<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Enum\WorkflowChannel;
use App\Exception\ApiException;
use App\Service\Prompt\PromptInterface;

class SubmitLabelResolver
{
    private const LABEL_CREATE_OPTION = 'Create: Create the label on GitHub and add it to the PR';
    private const LABEL_IGNORE_OPTION = 'Ignore: Skip this label and remove it from the final list';
    private const LABEL_RETRY_OPTION = 'Retry: Abort the command and re-run with a corrected list';

    public function __construct(
        private readonly GitProviderInterface $gitProvider,
        mixed $translator,
        private readonly PromptInterface $prompt,
    ) {
        unset($translator);
    }

    /**
     * @return array<int, string>|null
     */
    public function validateAndProcessLabels(WorkflowEntryRecorder $recorder, string $labelsInput, bool $quiet = false): ?array
    {
        $requestedLabels = $this->parseLabelInput($labelsInput);
        if ($requestedLabels === []) {
            return [];
        }

        $remoteLabels = $this->fetchRemoteLabels($recorder);
        if ($remoteLabels === null) {
            return null;
        }

        $existingLabelsMap = $this->buildExistingLabelsMap($remoteLabels);
        [$finalLabels, $unknownLabels] = $this->partitionKnownAndUnknownLabels($requestedLabels, $existingLabelsMap);

        foreach ($unknownLabels as $unknownLabel) {
            $result = $this->resolveUnknownLabel($recorder, $unknownLabel, $quiet, $finalLabels);
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
    public function fetchRemoteLabels(WorkflowEntryRecorder $recorder): ?array
    {
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.fetching_labels'), WorkflowChannel::Git);

        try {
            return $this->gitProvider->getLabels();
        } catch (ApiException $e) {
            $recorder->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('submit.error_fetch_labels', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_fetch_labels', ['error' => $e->getMessage()]));

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
    public function resolveUnknownLabel(WorkflowEntryRecorder $recorder, string $unknownLabel, bool $quiet, array $finalLabels): ?array
    {
        if ($quiet) {
            return $finalLabels;
        }

        $choice = $this->prompt->choice(
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
            $created = $this->createLabelOnProvider($recorder, $unknownLabel);

            return $created === null ? null : array_merge($finalLabels, [$created]);
        }

        $recorder->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, MessageRef::key('submit.label_ignored', ['label' => $unknownLabel]), WorkflowChannel::Git);

        return $finalLabels;
    }

    public function createLabelOnProvider(WorkflowEntryRecorder $recorder, string $unknownLabel): ?string
    {
        $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.label_creating', ['label' => $unknownLabel]), WorkflowChannel::Git);

        try {
            $color = sprintf('%06x', mt_rand(0, 0xffffff));
            $this->gitProvider->createLabel($unknownLabel, $color);
            $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.label_created', ['label' => $unknownLabel]));

            return $unknownLabel;
        } catch (ApiException $e) {
            $recorder->addErrorWithDetails(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return null;
        } catch (\Exception $e) {
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()]));

            return null;
        }
    }
}
