<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestFeedbackConversation;

/**
 * Git hosting provider methods that read remote data.
 *
 * Lookup handlers depend on this interface so they cannot open, update, comment on, or label a
 * pull request. {@see GitHostingPort} extends it and adds the methods that change remote data.
 */
interface GitHostingReadPort
{
    /**
     * Returns the authenticated provider user used by this token.
     *
     * @return array<string, mixed>
     */
    public function getAuthenticatedUser(): array;

    /**
     * Finds a pull request by branch head.
     *
     * @param string $head The branch head in format "owner:branch" or just "branch"
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'open')
     * @return array<string, mixed>|null The PR data or null if not found
     */
    public function findPullRequestByBranch(string $head, string $state = 'open'): ?array;

    /**
     * Finds a pull request by branch name (constructs owner:branch format automatically).
     *
     * @param string $branchName The branch name (without remote prefix)
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<string, mixed>|null The PR data or null if not found
     */
    public function findPullRequestByBranchName(string $branchName, string $state = 'all'): ?array;

    /**
     * Gets all labels for the repository.
     *
     * @return array<int, array<string, mixed>> Array of label data
     */
    public function getLabels(): array;

    /**
     * Fetches all pull requests for the repository.
     *
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<int, array<string, mixed>> Array of PR data arrays
     */
    public function getAllPullRequests(string $state = 'all'): array;

    /**
     * Fetches issue-level comments for a pull request (GitHub: issue comments; GitLab: MR notes).
     * Results may be capped (e.g. last 50) to limit output and respect rate limits.
     *
     * @return PullRequestComment[]
     */
    public function getPullRequestComments(int $issueNumber): array;

    /**
     * Fetches review/inline comments for a pull request (GitHub: pull request review comments; GitLab: MR discussion threads with position).
     * Results may be capped (e.g. last 50). Includes path/line when provided by the API.
     *
     * @return PullRequestComment[]
     */
    public function getPullRequestReviewComments(int $pullNumber): array;

    /**
     * Fetches PR review bodies (GitHub: pull request reviews with body, e.g. "Request changes" / "Comment"; GitLab: no direct equivalent, returns empty).
     * Distinct from inline review comments: this is the main review comment submitted with the review.
     *
     * @return PullRequestComment[]
     */
    public function getPullRequestReviews(int $pullNumber): array;

    /**
     * Fetches PR/MR feedback as grouped conversations with provider action identifiers.
     *
     * @return PullRequestFeedbackConversation[]
     */
    public function getPullRequestFeedbackConversations(int $pullNumber): array;
}
