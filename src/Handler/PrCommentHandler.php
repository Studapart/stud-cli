<?php

declare(strict_types=1);

namespace App\Handler;

use App\Service\GithubProvider;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use Symfony\Component\Console\Style\SymfonyStyle;

class PrCommentHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly ?GithubProvider $githubProvider,
        private readonly TranslationService $translator,
        private readonly Logger $logger
    ) {
    }

    public function handle(SymfonyStyle $io, ?string $message = null): int
    {
        $io->section($this->translator->trans('pr.comment.section'));

        // Check if GitHub provider is available
        if (! $this->githubProvider) {
            $io->error($this->translator->trans('pr.comment.error_no_provider'));

            return 1;
        }

        // Get comment body with precedence: STDIN first, then argument
        $commentBody = $this->getCommentBody($io, $message);

        if (empty($commentBody)) {
            $io->error($this->translator->trans('pr.comment.error_no_input'));

            return 1;
        }

        // Find the active PR for the current branch
        $prNumber = $this->findActivePullRequest($io);
        if ($prNumber === null) {
            $io->error($this->translator->trans('pr.comment.error_no_pr'));

            return 1;
        }

        // Post the comment
        try {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('pr.comment.posting', ['number' => $prNumber])}</>");
            $this->githubProvider->createComment($prNumber, $commentBody);
            $io->success($this->translator->trans('pr.comment.success', ['number' => $prNumber]));
        } catch (\Exception $e) {
            $io->error(explode("\n", $this->translator->trans('pr.comment.error_post', ['error' => $e->getMessage()])));

            return 1;
        }

        return 0;
    }

    /**
     * Gets comment body with precedence: STDIN first, then argument.
     * Returns null if neither is available.
     */
    protected function getCommentBody(SymfonyStyle $io, ?string $message): ?string
    {
        // 1st priority: Check for STDIN input (piped content)
        $stdinContent = $this->readStdin();
        if (! empty($stdinContent)) {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('pr.comment.using_stdin')}</>");

            return $stdinContent;
        }

        // 2nd priority: Use direct argument if provided
        if ($message !== null && ! empty(trim($message))) {
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>{$this->translator->trans('pr.comment.using_argument')}</>");

            return trim($message);
        }

        return null;
    }

    /**
     * Reads content from STDIN if available (non-blocking).
     * Returns empty string if STDIN is a TTY (interactive terminal) or if no content is available.
     */
    protected function readStdin(): string
    {
        // Check if STDIN is a TTY (interactive terminal)
        // If it is, there's no piped input
        // This path is only reachable when STDIN is actually a TTY, which cannot be simulated in unit tests
        // @codeCoverageIgnoreStart
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            return '';
        }
        // @codeCoverageIgnoreEnd

        // Try to read from STDIN non-blocking
        // Use stream_set_blocking to make it non-blocking
        // Reading from STDIN when it's a resource with actual piped content requires process execution
        // This path is tested via integration tests when the command is executed with piped input
        // @codeCoverageIgnoreStart
        if (is_resource(STDIN)) {
            $metaData = stream_get_meta_data(STDIN);
            $wasBlocking = $metaData['blocked'];
            stream_set_blocking(STDIN, false);
            $content = stream_get_contents(STDIN);
            stream_set_blocking(STDIN, $wasBlocking);

            if ($content === false) {
                return '';
            }

            return trim($content);
        }
        // @codeCoverageIgnoreEnd

        // Fallback: try file_get_contents on php://stdin
        // Reading from php://stdin in unit tests is not feasible as it requires actual process execution
        // @codeCoverageIgnoreStart
        if (! function_exists('posix_isatty') || ! posix_isatty(STDIN)) {
            $content = @file_get_contents('php://stdin');

            return $content !== false ? trim($content) : '';
        }
        // @codeCoverageIgnoreEnd

        // Final fallback return - only reached when STDIN is not a TTY, not a resource, and posix_isatty handling fails
        // This edge case cannot be easily simulated in unit tests
        // @codeCoverageIgnoreStart
        return '';
        // @codeCoverageIgnoreEnd
    }

    /**
     * Finds the active Pull Request number for the current branch.
     * Returns null if no PR is found.
     */
    protected function findActivePullRequest(SymfonyStyle $io): ?int
    {
        $branch = $this->gitRepository->getCurrentBranchName();

        // Format the head parameter for GitHub API
        // GitHub requires "owner:branch" format when creating PR from a fork
        $remoteOwner = $this->gitRepository->getRepositoryOwner('origin');
        $headBranch = $remoteOwner ? "{$remoteOwner}:{$branch}" : $branch;

        $this->logger->gitWriteln(Logger::VERBOSITY_VERBOSE, "  {$this->translator->trans('pr.comment.finding_pr', ['branch' => $branch])}");

        try {
            $pr = $this->githubProvider->findPullRequestByBranch($headBranch);
            if ($pr && isset($pr['number'])) {
                return $pr['number'];
            }
        } catch (\Exception $e) {
            // @codeCoverageIgnoreStart
            $this->logger->writeln(Logger::VERBOSITY_VERBOSE, "  <fg=gray>Error finding PR: {$e->getMessage()}</>");
            // @codeCoverageIgnoreEnd
        }

        return null;
    }
}
