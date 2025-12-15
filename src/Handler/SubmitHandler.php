<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\PullRequestData;
use App\Service\GithubProvider;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class SubmitHandler
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly ?GithubProvider $githubProvider,
        private readonly array $jiraConfig,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, bool $draft = false, ?string $labels = null): int
    {
        $io->section($this->translator->trans('submit.section'));

        // 1. Check for clean working directory
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            $io->error($this->translator->trans('submit.error_dirty_working'));

            return 1;
        }

        // 2. Get current branch name and check if it is a base branch
        $branch = $this->gitRepository->getCurrentBranchName();
        if (in_array($branch, ['develop', 'main', 'master'])) {
            $io->error($this->translator->trans('submit.error_base_branch'));

            return 1;
        }

        // 3. Push the branch
        $io->text($this->translator->trans('submit.pushing', ['branch' => $branch]));
        $pushProcess = $this->gitRepository->pushToOrigin('HEAD');
        if (! $pushProcess->isSuccessful()) {
            $io->error(explode("\n", $this->translator->trans('submit.error_push')));

            return 1;
        }

        // 4. Find the first logical commit
        $io->text($this->translator->trans('submit.finding_commit'));
        $ancestorSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $firstCommitSha = $this->gitRepository->findFirstLogicalSha($ancestorSha);

        if (null === $firstCommitSha) {
            $io->error($this->translator->trans('submit.error_no_logical'));

            return 1;
        }
        $firstLogicalMessage = $this->gitRepository->getCommitMessage($firstCommitSha);

        // 5. Parse PR details from commit message
        $prTitle = $firstLogicalMessage;
        preg_match('/(?i)\[([a-z]+-\d+)]/', $prTitle, $matches);
        $jiraKey = $matches[1] ?? null;

        if (! $jiraKey) {
            $io->error($this->translator->trans('submit.error_no_jira_key'));

            return 1;
        }

        // 6. Fetch Jira issue for PR body
        $prBody = null;

        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('submit.fetching_jira', ['key' => $jiraKey])}");
            $issue = $this->jiraService->getIssue($jiraKey, true); // Request rendered fields
            $prBody = $issue->renderedDescription;
        } catch (\Exception $e) {
            $io->warning(explode("\n", $this->translator->trans('submit.warning_jira_fetch', ['error' => $e->getMessage()])));
        }
        // Fallback if API fails or if description is empty/default
        if (empty($prBody)) {
            $prBody = "Resolves: {$this->jiraConfig['JIRA_URL']}/browse/{$jiraKey}";
        }

        // 6.5. Prepend clickable Jira link to PR body
        $prBody = $this->prependJiraLinkToBody($prBody, $jiraKey);

        // 7. Format the head parameter for GitHub API
        // GitHub requires "owner:branch" format when creating PR from a fork
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$branch}" : $branch;

        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('submit.using_head', ['head' => $headBranch])}");

        // 8. Validate and process labels if provided
        $finalLabels = [];
        if ($labels !== null && $this->githubProvider) {
            $finalLabels = $this->validateAndProcessLabels($io, $labels);
            if ($finalLabels === null) {
                // User chose to retry, abort the command
                return 1;
            }
        }

        // 9. Call the Git Provider API
        $io->text($this->translator->trans('submit.creating'));

        try {
            if ($this->githubProvider) {
                $prRequestData = new PullRequestData($prTitle, $headBranch, 'develop', $prBody, $draft);
                $prData = $this->githubProvider->createPullRequest($prRequestData);

                // Add labels to PR if any were provided
                if (! empty($finalLabels)) {
                    $io->text($this->translator->trans('submit.adding_labels'));
                    $this->githubProvider->addLabelsToPullRequest($prData['number'], $finalLabels);
                }

                $io->success($this->translator->trans('submit.success_created', ['url' => $prData['html_url']]));
            } else {
                $io->warning($this->translator->trans('submit.warning_no_provider'));
            }
        } catch (\Exception $e) {
            // Check if PR already exists (GitHub returns 422 status)
            $errorMessage = $e->getMessage();
            $lowerMessage = strtolower($errorMessage);

            // GitHub returns 422 with "A pull request already exists" message
            if (str_contains($errorMessage, 'Status: 422') &&
                str_contains($lowerMessage, 'pull request already exists')) {
                $io->note($this->translator->trans('submit.note_pr_exists'));

                // Find the existing PR and apply labels/draft if needed
                if ($this->githubProvider) {
                    try {
                        $existingPr = $this->githubProvider->findPullRequestByBranch($headBranch);

                        if ($existingPr) {
                            $prNumber = $existingPr['number'];

                            // Apply labels if provided
                            if (! empty($finalLabels)) {
                                $io->text($this->translator->trans('submit.adding_labels'));

                                try {
                                    $this->githubProvider->addLabelsToPullRequest($prNumber, $finalLabels);
                                } catch (\Exception $labelError) {
                                    $io->warning(explode("\n", $this->translator->trans('submit.error_add_labels', ['error' => $labelError->getMessage()])));
                                }
                            }

                            // Update draft status if --draft flag is set
                            if ($draft && ! $existingPr['draft']) {
                                $io->text($this->translator->trans('submit.updating_to_draft'));

                                try {
                                    $this->githubProvider->updatePullRequest($prNumber, true);
                                } catch (\Exception $draftError) {
                                    $io->warning(explode("\n", $this->translator->trans('submit.error_update_draft', ['error' => $draftError->getMessage()])));
                                }
                            }
                        }
                    } catch (\Exception $findError) {
                        // If we can't find the PR, just continue with success message
                        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Could not find existing PR: {$findError->getMessage()}</>");
                    }
                }

                $io->success($this->translator->trans('submit.success_pushed'));

                return 0;
            }

            $io->error(explode("\n", $this->translator->trans('submit.error_create_pr', ['error' => $errorMessage])));

            return 1;
        }

        return 0;
    }

    protected function prependJiraLinkToBody(string $body, string $jiraKey): string
    {
        $jiraUrl = $this->jiraConfig['JIRA_URL'];
        $jiraLink = "🔗 **Jira Issue:** [{$jiraKey}]({$jiraUrl}/browse/{$jiraKey})";

        return $jiraLink . "\n\n" . $body;
    }

    /**
     * Validates and processes labels, handling unknown labels interactively.
     *
     * @param SymfonyStyle $io
     * @param string $labelsInput Comma-separated string of labels
     * @return array|null Array of final labels to apply, or null if user chose to retry
     */
    /**
     * @return array<string>|null
     */
    protected function validateAndProcessLabels(SymfonyStyle $io, string $labelsInput): ?array
    {
        // 1. Parse input into clean array
        $requestedLabels = array_map('trim', explode(',', $labelsInput));
        $requestedLabels = array_filter($requestedLabels, fn ($label) => ! empty($label));
        $requestedLabels = array_values($requestedLabels);

        if (empty($requestedLabels)) {
            return [];
        }

        // 2. Fetch remote labels
        $io->text($this->translator->trans('submit.fetching_labels'));

        try {
            $remoteLabels = $this->githubProvider->getLabels();
        } catch (\Exception $e) {
            $io->error(explode("\n", $this->translator->trans('submit.error_fetch_labels', ['error' => $e->getMessage()])));

            return null;
        }

        // Create a map of existing label names (case-insensitive)
        $existingLabelsMap = [];
        foreach ($remoteLabels as $label) {
            $existingLabelsMap[strtolower($label['name'])] = $label['name'];
        }

        // 3. Compare and bucket labels
        $finalLabels = [];
        $unknownLabels = [];

        foreach ($requestedLabels as $requestedLabel) {
            $normalizedLabel = strtolower($requestedLabel);
            if (isset($existingLabelsMap[$normalizedLabel])) {
                // Use the exact case from GitHub
                $finalLabels[] = $existingLabelsMap[$normalizedLabel];
            } else {
                $unknownLabels[] = $requestedLabel;
            }
        }

        // 4. Interactive resolution for unknown labels
        foreach ($unknownLabels as $unknownLabel) {
            $choice = $io->choice(
                $this->translator->trans('submit.label_unknown_prompt', ['label' => $unknownLabel]),
                [
                    $this->translator->trans('submit.label_create_option'),
                    $this->translator->trans('submit.label_ignore_option'),
                    $this->translator->trans('submit.label_retry_option'),
                ],
                0
            );

            if ($choice === $this->translator->trans('submit.label_retry_option')) {
                // User chose to retry, abort
                return null;
            }

            if ($choice === $this->translator->trans('submit.label_create_option')) {
                // Create the label
                $io->text($this->translator->trans('submit.label_creating', ['label' => $unknownLabel]));

                try {
                    // Generate a random color (GitHub requires 6 hex digits)
                    $color = sprintf('%06x', mt_rand(0, 0xffffff));
                    $this->githubProvider->createLabel($unknownLabel, $color);
                    $finalLabels[] = $unknownLabel;
                    $io->success($this->translator->trans('submit.label_created', ['label' => $unknownLabel]));
                } catch (\Exception $e) {
                    $io->error(explode("\n", $this->translator->trans('submit.error_create_label', ['label' => $unknownLabel, 'error' => $e->getMessage()])));

                    return null;
                }
            } else {
                // Ignore the label
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('submit.label_ignored', ['label' => $unknownLabel])}</>");
            }
        }

        return $finalLabels;
    }
}
