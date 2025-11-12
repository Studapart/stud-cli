<?php

namespace App\Handler;

use App\Service\GitRepository;
use App\Service\GithubProvider;
use App\Service\JiraService;
use Symfony\Component\Console\Style\SymfonyStyle;

class SubmitHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly JiraService $jiraService,
        private readonly ?GithubProvider $githubProvider,
        private readonly array $jiraConfig,
        private readonly string $baseBranch
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section('Submitting Pull Request');

        // 1. Check for clean working directory
        $gitStatus = $this->gitRepository->getPorcelainStatus();
        if (!empty($gitStatus)) {
            $io->error('Your working directory is not clean. Please commit your changes with \'stud commit\' before submitting.');
            return 1;
        }

        // 2. Get current branch name and check if it is a base branch
        $branch = $this->gitRepository->getCurrentBranchName();
        if (in_array($branch, ['develop', 'main', 'master'])) {
            $io->error('Cannot create a Pull Request from the base branch.');
            return 1;
        }

        // 3. Push the branch
        $io->text("Pushing branch <info>{$branch}</info>...");
        $pushProcess = $this->gitRepository->pushToOrigin('HEAD');
        if (!$pushProcess->isSuccessful()) {
            $io->error([
                'Push failed. Your branch may have rewritten history.',
                "Try running 'stud please' to force-push.",
            ]);
            return 1;
        }

        // 4. Find the first logical commit
        $io->text('Finding first logical commit to use for PR details...');
        $ancestorSha = $this->gitRepository->getMergeBase($this->baseBranch, 'HEAD');
        $firstCommitSha = $this->gitRepository->findFirstLogicalSha($ancestorSha);

        if (null === $firstCommitSha) {
            $io->error('Could not find a logical commit on this branch. Cannot create PR.');
            return 1;
        }
        $firstLogicalMessage = $this->gitRepository->getCommitMessage($firstCommitSha);

        // 5. Parse PR details from commit message
        $prTitle = $firstLogicalMessage;
        preg_match('/(?i)\[([a-z]+-\d+)]/', $prTitle, $matches);
        $jiraKey = $matches[1] ?? null;

        if (!$jiraKey) {
            $io->error('Could not parse Jira key from commit message. Cannot create PR.');
            return 1;
        }

        // 6. Fetch Jira issue for PR body
        $prBody = null;
        try {
            if ($io->isVerbose()) {
                $io->writeln("  <fg=gray>Fetching Jira issue for PR body: {$jiraKey}</>");
            }
            $issue = $this->jiraService->getIssue($jiraKey, true); // Request rendered fields
            $prBody = $issue->renderedDescription;
        } catch (\Exception $e) {
            $io->warning([
                'Could not fetch Jira issue details for PR body: ' . $e->getMessage(),
                'Falling back to a simple link.',
            ]);
        }
        // Fallback if API fails or if description is empty/default
        if (empty($prBody)) {
            $prBody = "Resolves: {$this->jiraConfig['JIRA_URL']}/browse/{$jiraKey}";
        }

        // 7. Format the head parameter for GitHub API
        // GitHub requires "owner:branch" format when creating PR from a fork
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$branch}" : $branch;

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Using head branch: {$headBranch}</>");
        }

        // 8. Call the Git Provider API
        $io->text('Creating Pull Request...');

        try {
            if ($this->githubProvider) {
                $prData = $this->githubProvider->createPullRequest($prTitle, $headBranch, 'develop', $prBody);
                $io->success("✅ Pull Request created: {$prData['html_url']}");
            } else {
                $io->warning('No Git provider configured for this project.');
            }
        } catch (\Exception $e) {
            // Check if PR already exists (GitHub returns 422 status)
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'Status: 422') && 
                (str_contains(strtolower($errorMessage), 'pull request already exists') || 
                 str_contains(strtolower($errorMessage), 'a pull request already exists'))) {
                $io->warning([
                    'A Pull Request already exists for this branch.',
                    'Your changes have been pushed successfully.',
                ]);
                return 0;
            }
            
            $io->error([
                'Failed to create Pull Request.',
                'Error: ' . $errorMessage,
            ]);
            return 1;
        }
        
        return 0;
    }
}
