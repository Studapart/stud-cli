<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\DTO\WorkItem;
use App\Service\Prompt\PromptInterface;

/**
 * Builds a conventional commit message from a work item, letting the user override type, scope and summary.
 */
class CommitMessageComposer
{
    public function __construct(
        private readonly PromptInterface $prompt,
    ) {
    }

    public function compose(WorkItem $issue, string $key, bool $quiet): string
    {
        $detectedType = $this->getCommitTypeFromIssueType($issue->issueType);
        $defaultScope = ! empty($issue->components) ? $issue->components[0] : null;
        if ($quiet) {
            $type = $detectedType;
            $scope = $defaultScope;
            $summary = $issue->title;
        } else {
            [$type, $scope, $summary] = $this->promptTypeScopeSummary($issue, $detectedType, $defaultScope);
            $type = $type ?? $detectedType;
            $scope = $scope ?? $defaultScope;
            $summary = $summary ?? $issue->title;
        }

        return "{$type}" . ($scope ? "({$scope})" : '') . ": {$summary} [{$key}]";
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function promptTypeScopeSummary(WorkItem $issue, string $detectedType, ?string $defaultScope): array
    {
        $scopePrompt = ! empty($issue->components)
            ? MessageRef::key('commit.scope_auto', ['scope' => $defaultScope])
            : MessageRef::key('commit.scope_prompt');
        $type = $this->prompt->ask(MessageRef::key('commit.type_prompt', ['type' => $detectedType]), $detectedType);
        $scope = $this->prompt->ask($scopePrompt, $defaultScope);
        $summary = $this->prompt->ask(MessageRef::key('commit.summary_prompt'), $issue->title);

        return [$type, $scope, $summary];
    }

    protected function getCommitTypeFromIssueType(string $issueType): string
    {
        return match (strtolower($issueType)) {
            'bug' => 'fix',
            'story', 'epic' => 'feat',
            'task', 'sub-task' => 'chore',
            default => 'feat',
        };
    }
}
