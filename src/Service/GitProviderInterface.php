<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestData;

/**
 * Interface for Git hosting provider implementations (GitHub, GitLab, etc.).
 *
 * This interface defines the contract that all Git provider implementations must follow,
 * ensuring consistent behavior across different hosting platforms.
 */
interface GitProviderInterface
{
    /**
     * Creates a pull request (GitHub) or merge request (GitLab).
     *
     * @return array<string, mixed> The created PR/MR data
     */
    public function createPullRequest(PullRequestData $prData): array;

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
     * Adds labels to a pull request.
     *
     * @param int $issueNumber The PR/MR number
     * @param array<string> $labels Array of label names to add
     */
    public function addLabelsToPullRequest(int $issueNumber, array $labels): void;

    /**
     * Creates a comment on a pull request.
     *
     * @param int $issueNumber The PR/MR number
     * @param string $body The comment body
     * @return array<string, mixed> The created comment data
     */
    public function createComment(int $issueNumber, string $body): array;

    /**
     * Updates a pull request (e.g., draft/WIP status).
     *
     * @param int $pullNumber The PR/MR number
     * @param bool $draft Whether the PR should be in draft/WIP status
     * @return array<string, mixed> The updated PR data
     */
    public function updatePullRequest(int $pullNumber, bool $draft): array;

    /**
     * Gets all labels for the repository.
     *
     * @return array<int, array<string, mixed>> Array of label data
     */
    public function getLabels(): array;

    /**
     * Creates a new label in the repository.
     *
     * @param string $name The label name
     * @param string $color The label color (hex format)
     * @param string|null $description Optional label description
     * @return array<string, mixed> The created label data
     */
    public function createLabel(string $name, string $color, ?string $description = null): array;

    /**
     * Fetches all pull requests for the repository.
     *
     * @param string $state The PR state: 'open', 'closed', or 'all' (default: 'all')
     * @return array<int, array<string, mixed>> Array of PR data arrays
     */
    public function getAllPullRequests(string $state = 'all'): array;
}
