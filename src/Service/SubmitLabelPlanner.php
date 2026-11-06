<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\SubmitOptions;
use App\Service\Prompt\PromptInterface;

/**
 * Plans submit pull-request labels from --labels or the project pull-request label default.
 */
class SubmitLabelPlanner
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GitHostingPort $gitProvider,
        private readonly mixed $translator,
        private readonly PromptInterface $prompt,
    ) {
    }

    /**
     * "create" labels go on a new pull request; "existing" labels go on an already-open one.
     *
     * @return array{create: array<string>, existing: array<string>}|null null if user chose retry
     */
    public function plan(WorkflowEntryRecorder $recorder, SubmitOptions $options): ?array
    {
        if ($options->labels === null) {
            return $this->planOmittedLabels($recorder, $options->quiet);
        }

        $resolved = $this->resolveLabels($recorder, $options->labels, $options->quiet);
        if ($resolved === null) {
            return null;
        }

        return ['create' => $resolved, 'existing' => $resolved];
    }

    /**
     * @return array{create: array<string>, existing: array<string>}|null
     */
    private function planOmittedLabels(WorkflowEntryRecorder $recorder, bool $quiet): ?array
    {
        $defaults = $this->readPullRequestLabels();
        if ($defaults === []) {
            return ['create' => [], 'existing' => []];
        }

        $catalogError = $this->pullRequestLabelCatalogError();
        if ($catalogError !== null) {
            $recorder->addWarning(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                MessageRef::key('submit.warning_label_default_unavailable', ['error' => $catalogError]),
            );

            return ['create' => [], 'existing' => []];
        }

        $resolved = $this->resolveLabels($recorder, implode(',', $defaults), $quiet);
        if ($resolved === null) {
            return null;
        }

        return ['create' => $resolved, 'existing' => []];
    }

    /**
     * @return array<string>|null null if user chose retry
     */
    private function resolveLabels(WorkflowEntryRecorder $recorder, string $labels, bool $quiet): ?array
    {
        if (! $this->gitProvider) {
            return [];
        }

        return (new SubmitLabelResolver($this->gitProvider, $this->translator, $this->prompt))
            ->validateAndProcessLabels($recorder, $labels, $quiet);
    }

    private function pullRequestLabelCatalogError(): ?string
    {
        try {
            $this->gitProvider?->getLabels();
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function readPullRequestLabels(): array
    {
        try {
            return ProjectStudConfigKeys::readLabelList(
                $this->gitRepository->readProjectConfig(),
                ProjectStudConfigKeys::PULL_REQUEST_LABELS,
            );
        } catch (\RuntimeException) {
            return [];
        }
    }
}
