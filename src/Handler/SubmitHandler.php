<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\PullRequestData;
use App\Exception\ApiException;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\GitProviderInterface;
use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\Logger;
use App\Service\MarkdownHelper;
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
        private readonly ?GitProviderInterface $githubProvider,
        private readonly array $jiraConfig,
        private readonly string $baseBranch,
        private readonly TranslationService $translator,
        private readonly Logger $logger,
        private readonly CanConvertToMarkdownInterface $htmlConverter
    ) {
    }

    public function handle(SymfonyStyle $io, bool $draft = false, ?string $labels = null, bool $quiet = false): int
    {
        $this->logger->section(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.section'));

        $preflight = $this->runSubmitPreflight();
        if ($preflight['exitCode'] !== 0) {
            return $preflight['exitCode'];
        }
        /** @var array{exitCode: 0, branch: string, jiraKey: string, prTitle: string} $preflight */
        $branch = $preflight['branch'];
        $jiraKey = $preflight['jiraKey'];
        $prTitle = $preflight['prTitle'];

        $prBody = $this->buildPrBody($jiraKey);

        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = ($remoteOwner !== null && $remoteOwner !== '') ? "{$remoteOwner}:{$branch}" : $branch;
        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('submit.using_head', ['head' => $headBranch])}");

        $finalLabels = $this->resolveLabels($labels, $quiet);
        if ($finalLabels === null) {
            return 1;
        }

        return $this->createPullRequest($prTitle, $headBranch, $prBody, $draft, $finalLabels);
    }

    /**
     * Run preflight checks: optional dirty-tree note, branch, push, first commit, Jira key.
     *
     * @return array{exitCode: int, branch?: string, jiraKey?: string, prTitle?: string}
     */
    protected function runSubmitPreflight(): array
    {
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (! empty($gitStatus)) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.note_dirty_working'));
        }

        $branch = $this->gitRepository->getCurrentBranchName();
        $baseBranchName = str_replace('origin/', '', $this->baseBranch);
        if ($branch === $baseBranchName || in_array($branch, ['main', 'master'], true)) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_base_branch'));

            return ['exitCode' => 1];
        }

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.pushing', ['branch' => $branch]));
        $pushProcess = $this->gitRepository->pushHeadToOrigin();
        if (! $pushProcess->isSuccessful()) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_push')));

            return ['exitCode' => 1];
        }

        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.finding_commit'));
        $ancestorSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $firstCommitSha = $this->gitRepository->findFirstLogicalSha($ancestorSha);
        if ($firstCommitSha === null) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_no_logical'));

            return ['exitCode' => 1];
        }

        $firstLogicalMessage = $this->gitRepository->getCommitMessage($firstCommitSha);
        $jiraKey = $this->resolveJiraKey($firstLogicalMessage);
        if ($jiraKey === null) {
            $this->logger->error(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.error_no_jira_key'));

            return ['exitCode' => 1];
        }

        return [
            'exitCode' => 0,
            'branch' => $branch,
            'jiraKey' => $jiraKey,
            'prTitle' => $firstLogicalMessage,
        ];
    }

    private function resolveJiraKey(string $commitMessage): ?string
    {
        $jiraKey = $this->gitRepository->getJiraKeyFromBranchName();
        if ($jiraKey === null || $jiraKey === '') {
            preg_match('/(?i)\[([a-z]+-\d+)]/', $commitMessage, $matches);
            $jiraKey = $matches[1] ?? null;
        }

        return $jiraKey;
    }

    /**
     * Build PR body: fetch Jira description, convert to Markdown if needed, prepend Jira link.
     */
    protected function buildPrBody(string $jiraKey): string
    {
        $prBody = $this->fetchJiraDescription($jiraKey);
        if ($prBody !== null && $prBody !== '') {
            $prBody = $this->convertDescriptionToMarkdown($prBody);
        }
        if ($prBody === null || $prBody === '') {
            $prBody = "Resolves: {$this->jiraConfig['JIRA_URL']}/browse/{$jiraKey}";
        }

        return $this->prependJiraLinkToBody($prBody, $jiraKey);
    }

    /**
     * Fetch rendered description from Jira; log warnings on failure.
     */
    protected function fetchJiraDescription(string $jiraKey): ?string
    {
        try {
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('submit.fetching_jira', ['key' => $jiraKey])}");
            $issue = $this->jiraService->getIssue($jiraKey, true);

            return $issue->renderedDescription;
        } catch (ApiException $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.warning_jira_fetch', ['error' => $e->getMessage()])));
            $this->logger->text(Logger::VERBOSITY_VERBOSE, ['', ' Technical details: ' . $e->getTechnicalDetails()]);

            return null;
        } catch (\Exception $e) {
            $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.warning_jira_fetch', ['error' => $e->getMessage()])));

            return null;
        }
    }

    /**
     * Convert HTML description to Markdown; log and fallback to raw HTML on failure.
     */
    protected function convertDescriptionToMarkdown(string $html): string
    {
        try {
            $markdown = $this->htmlConverter->toMarkdown($html);
            $markdown = MarkdownHelper::unescapeCheckboxMarkdown($markdown);
            $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, '  Converted HTML to Markdown for PR description');

            return $markdown;
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
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
                // @codeCoverageIgnoreStart
                $this->logger->jiraWriteln(Logger::VERBOSITY_VERBOSE, "  HTML to Markdown conversion failed, using raw HTML: {$errorMessage}");
                // @codeCoverageIgnoreEnd
            }

            return $html;
        }
    }

    /**
     * Resolve labels: empty array if none; validate/process if provided and provider exists.
     *
     * @return array<string>|null null if user chose retry
     */
    protected function resolveLabels(?string $labels, bool $quiet): ?array
    {
        if ($labels === null || ! $this->githubProvider) {
            return [];
        }

        return $this->validateAndProcessLabels($labels, $quiet);
    }

    /**
     * Create PR via provider; handle 422 "already exists" by updating existing PR.
     *
     * @param array<string> $finalLabels
     */
    protected function createPullRequest(string $prTitle, string $headBranch, string $prBody, bool $draft, array $finalLabels): int
    {
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.creating'));

        try {
            if (! $this->githubProvider) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.warning_no_provider'));

                return 0;
            }

            $baseBranchName = str_replace('origin/', '', $this->baseBranch);
            $prRequestData = new PullRequestData($prTitle, $headBranch, $baseBranchName, $prBody, $draft);
            $prData = $this->githubProvider->createPullRequest($prRequestData);

            if (! empty($finalLabels)) {
                $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.adding_labels'));
                $this->githubProvider->addLabelsToPullRequest($prData['number'], $finalLabels);
            }
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_created', ['url' => $prData['html_url']]));

            return 0;
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 422 && str_contains(strtolower($e->getTechnicalDetails()), 'pull request already exists')) {
                $this->handleExistingPr($headBranch, $draft, $finalLabels);

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
    }

    /**
     * When PR already exists: find it, apply labels and draft update, log success.
     *
     * @param array<string> $finalLabels
     */
    protected function handleExistingPr(string $headBranch, bool $draft, array $finalLabels): void
    {
        $this->logger->note(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.note_pr_exists'));
        if (! $this->githubProvider) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_pushed'));

            return;
        }

        try {
            $existingPr = $this->githubProvider->findPullRequestByBranch($headBranch);
        } catch (\Exception $findError) {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Could not find existing PR: {$findError->getMessage()}</>");
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_pushed'));

            return;
        }

        if ($existingPr === null) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_pushed'));

            return;
        }

        $prNumber = $existingPr['number'];
        if (! empty($finalLabels)) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.adding_labels'));

            try {
                $this->githubProvider->addLabelsToPullRequest($prNumber, $finalLabels);
            } catch (\Exception $labelError) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_add_labels', ['error' => $labelError->getMessage()])));
            }
        }
        if ($draft && ! $existingPr['draft']) {
            $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.updating_to_draft'));

            try {
                $this->githubProvider->updatePullRequest($prNumber, true);
            } catch (\Exception $draftError) {
                $this->logger->warning(Logger::VERBOSITY_NORMAL, explode("\n", $this->translator->trans('submit.error_update_draft', ['error' => $draftError->getMessage()])));
            }
        }
        $this->logger->success(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.success_pushed'));
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
     * @return array<string>|null Array of final labels to apply, or null if user chose to retry
     */
    protected function validateAndProcessLabels(string $labelsInput, bool $quiet = false): ?array
    {
        $requestedLabels = $this->parseLabelInput($labelsInput);
        if (empty($requestedLabels)) {
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
    protected function parseLabelInput(string $labelsInput): array
    {
        $requestedLabels = array_map('trim', explode(',', $labelsInput));
        $requestedLabels = array_filter($requestedLabels, fn (string $label): bool => $label !== '');

        return array_values($requestedLabels);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchRemoteLabels(): ?array
    {
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.fetching_labels'));

        try {
            return $this->githubProvider->getLabels();
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
     * @param array<int, array{name: string}> $remoteLabels
     *
     * @return array<string, string> lowercase name => canonical name
     */
    /**
     * @param array<int, array<string, mixed>> $remoteLabels
     * @return array<string, string>
     */
    protected function buildExistingLabelsMap(array $remoteLabels): array
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
     * @return array{array<int, string>, array<int, string>} [finalLabels, unknownLabels]
     */
    protected function partitionKnownAndUnknownLabels(array $requestedLabels, array $existingLabelsMap): array
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
     * Resolve one unknown label (create/ignore/retry). Returns updated finalLabels or null on retry.
     *
     * @param array<int, string> $finalLabels
     *
     * @return array<int, string>|null
     */
    protected function resolveUnknownLabel(string $unknownLabel, bool $quiet, array $finalLabels): ?array
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

    /**
     * Create label on GitHub; return label name on success, null on error (caller should abort).
     */
    protected function createLabelOnProvider(string $unknownLabel): ?string
    {
        $this->logger->text(Logger::VERBOSITY_NORMAL, $this->translator->trans('submit.label_creating', ['label' => $unknownLabel]));

        try {
            $color = sprintf('%06x', mt_rand(0, 0xffffff));
            $this->githubProvider->createLabel($unknownLabel, $color);
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
