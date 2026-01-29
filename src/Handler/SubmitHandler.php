<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\PullRequestData;
use App\Exception\ApiException;
use App\Service\CanConvertToMarkdownInterface;
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
        private readonly Logger $logger,
        private readonly CanConvertToMarkdownInterface $htmlConverter
    ) {
    }

    public function handle(SymfonyStyle $io, bool $draft = false, ?string $labels = null): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.section'));

        // 1. Check for clean working directory
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_dirty_working'));

            return 1;
        }

        // 2. Get current branch name and check if it is a base branch
        $branch = $this->gitRepository->getCurrentBranchName();
        $baseBranchName = str_replace('origin/', '', $this->baseBranch);
        if ($branch === $baseBranchName || in_array($branch, ['main', 'master'])) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_base_branch'));

            return 1;
        }

        // 3. Push the branch
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.pushing', ['branch' => $branch]));
        $pushProcess = $this->gitRepository->pushToOrigin('HEAD');
        if (! $pushProcess->isSuccessful()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_push')));

            return 1;
        }

        // 4. Find the first logical commit
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.finding_commit'));
        $ancestorSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $firstCommitSha = $this->gitRepository->findFirstLogicalSha($ancestorSha);

        if (null === $firstCommitSha) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_no_logical'));

            return 1;
        }
        $firstLogicalMessage = $this->gitRepository->getCommitMessage($firstCommitSha);

        // 5. Parse PR details - try branch name first, then commit message
        // Branch name is more reliable as it's always present and follows a consistent pattern
        $jiraKey = $this->gitRepository->getJiraKeyFromBranchName();

        // Fallback to commit message if branch name doesn't contain Jira key
        if (! $jiraKey) {
            $prTitle = $firstLogicalMessage;
            preg_match('/(?i)\[([a-z]+-\d+)]/', $prTitle, $matches);
            $jiraKey = $matches[1] ?? null;
        }

        if (! $jiraKey) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_no_jira_key'));

            return 1;
        }

        // Use commit message for PR title (preserves commit convention)
        $prTitle = $firstLogicalMessage;

        // 6. Fetch Jira issue for PR body
        $prBody = null;

        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('submit.fetching_jira', ['key' => $jiraKey])}");
            $issue = $this->jiraService->getIssue($jiraKey, true); // Request rendered fields
            $prBody = $issue->renderedDescription;
        } catch (ApiException $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.warning_jira_fetch', ['error' => $e->getMessage()])));
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.warning_jira_fetch', ['error' => $e->getMessage()])));
        }
        // Fallback if API fails or if description is empty/default
        if (empty($prBody)) {
            $prBody = "Resolves: {$this->jiraConfig['JIRA_URL']}/browse/{$jiraKey}";
        } else {
            // Convert HTML to Markdown for better readability on GitHub
            try {
                $prBody = $this->htmlConverter->toMarkdown($prBody);
                $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, '  Converted HTML to Markdown for PR description');
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                // Check if exception is related to missing XML extension
                if (str_contains($errorMessage, 'DOMDocument') || str_contains($errorMessage, "Class 'DOMDocument' not found")) {
                    $this->logger->warning(Logger::VERBOSITY_NORMAL, [
                        'HTML to Markdown conversion failed: PHP XML extension is missing.',
                        'Install it using:',
                        '  Ubuntu/Debian: sudo apt-get install php-xml',
                        '  Fedora/RHEL: sudo dnf install php-xml',
                        '  macOS (Homebrew): brew install php-xml',
                        'Using raw HTML for PR description.',
                    ]);
                } else {
                    // Non-DOMDocument exceptions are rare and hard to simulate in tests
                    // The DOMDocument exception path is tested, and this else branch provides fallback behavior
                    // @codeCoverageIgnoreStart
                    $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  HTML to Markdown conversion failed, using raw HTML: {$errorMessage}");
                    // @codeCoverageIgnoreEnd
                }
                // prBody remains as original HTML (fallback behavior)
            }
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
            $finalLabels = $this->validateAndProcessLabels($labels);
            if ($finalLabels === null) {
                // User chose to retry, abort the command
                return 1;
            }
        }

        // 9. Call the Git Provider API
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.creating'));

        try {
            if ($this->githubProvider) {
                $baseBranchName = str_replace('origin/', '', $this->baseBranch);
                $prRequestData = new PullRequestData($prTitle, $headBranch, $baseBranchName, $prBody, $draft);
                $prData = $this->githubProvider->createPullRequest($prRequestData);

                // Add labels to PR if any were provided
                if (! empty($finalLabels)) {
                    $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.adding_labels'));
                    $this->githubProvider->addLabelsToPullRequest($prData['number'], $finalLabels);
                }

                $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_created', ['url' => $prData['html_url']]));
            } else {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.warning_no_provider'));
            }
        } catch (ApiException $e) {
            // Check if PR already exists (GitHub returns 422 status)
            $statusCode = $e->getStatusCode();
            $technicalDetails = strtolower($e->getTechnicalDetails());

            // GitHub returns 422 with "A pull request already exists" message in technical details
            if ($statusCode === 422 &&
                str_contains($technicalDetails, 'pull request already exists')) {
                $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.note_pr_exists'));

                // Find the existing PR and apply labels/draft if needed
                if ($this->githubProvider) {
                    try {
                        $existingPr = $this->githubProvider->findPullRequestByBranch($headBranch);

                        if ($existingPr) {
                            $prNumber = $existingPr['number'];

                            // Apply labels if provided
                            if (! empty($finalLabels)) {
                                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.adding_labels'));

                                try {
                                    $this->githubProvider->addLabelsToPullRequest($prNumber, $finalLabels);
                                } catch (\Exception $labelError) {
                                    $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_add_labels', ['error' => $labelError->getMessage()])));
                                }
                            }

                            // Update draft status if --draft flag is set
                            if ($draft && ! $existingPr['draft']) {
                                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.updating_to_draft'));

                                try {
                                    $this->githubProvider->updatePullRequest($prNumber, true);
                                } catch (\Exception $draftError) {
                                    $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_update_draft', ['error' => $draftError->getMessage()])));
                                }
                            }
                        }
                    } catch (\Exception $findError) {
                        // If we can't find the PR, just continue with success message
                        $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Could not find existing PR: {$findError->getMessage()}</>");
                    }
                }

                $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_pushed'));

                return 0;
            }

            $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->translator->trans('submit.error_create_pr', ['error' => $e->getMessage()]),
                $e->getTechnicalDetails()
            );

            return 1;
        } catch (\Exception $e) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_create_pr', ['error' => $e->getMessage()])));

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
     * @param string $labelsInput Comma-separated string of labels
     * @return array|null Array of final labels to apply, or null if user chose to retry
     */
    /**
     * @return array<string>|null
     */
    protected function validateAndProcessLabels(string $labelsInput): ?array
    {
        // 1. Parse input into clean array
        $requestedLabels = array_map('trim', explode(',', $labelsInput));
        $requestedLabels = array_filter($requestedLabels, fn ($label) => ! empty($label));
        $requestedLabels = array_values($requestedLabels);

        if (empty($requestedLabels)) {
            return [];
        }

        // 2. Fetch remote labels
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.fetching_labels'));

        try {
            $remoteLabels = $this->githubProvider->getLabels();
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
                // User chose to retry, abort
                return null;
            }

            if ($choice === $this->translator->trans('submit.label_create_option')) {
                // Create the label
                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.label_creating', ['label' => $unknownLabel]));

                try {
                    // Generate a random color (GitHub requires 6 hex digits)
                    $color = sprintf('%06x', mt_rand(0, 0xffffff));
                    $this->githubProvider->createLabel($unknownLabel, $color);
                    $finalLabels[] = $unknownLabel;
                    $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.label_created', ['label' => $unknownLabel]));
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
            } else {
                // Ignore the label
                $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('submit.label_ignored', ['label' => $unknownLabel])}</>");
            }
        }

        return $finalLabels;
    }
}
