<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\GithubProvider;
use App\Service\JiraService;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class SubmitHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly ?GithubProvider $githubProvider,
        private readonly array $jiraConfig,
        private readonly string $baseBranch,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section($this->translator->trans('submit.section'));

        // 1. Check for clean working directory
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (!empty($gitStatus)) {
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
        if (!$pushProcess->isSuccessful()) {
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

        if (!$jiraKey) {
            $io->error($this->translator->trans('submit.error_no_jira_key'));
            return 1;
        }

        // 6. Fetch Jira issue for PR body
        $prBody = null;
        try {
            if ($io->isVerbose()) {
                $io->writeln("  <fg=gray>{$this->translator->trans('submit.fetching_jira', ['key' => $jiraKey])}</>");
            }
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

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>{$this->translator->trans('submit.using_head', ['head' => $headBranch])}</>");
        }

        // 8. Call the Git Provider API
        $io->text($this->translator->trans('submit.creating'));

        try {
            if ($this->githubProvider) {
                $prData = $this->githubProvider->createPullRequest($prTitle, $headBranch, 'develop', $prBody);
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
}
