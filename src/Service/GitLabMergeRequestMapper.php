<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\PullRequestComment;

class GitLabMergeRequestMapper
{
    /**
     * Normalizes GitLab merge request data to match GitHub pull request format.
     *
     * @param array<string, mixed> $mrData GitLab MR data
     * @return array<string, mixed> Normalized PR data
     */
    public function normalizeMergeRequestData(array $mrData): array
    {
        return [
            'number' => $mrData['iid'] ?? $mrData['id'] ?? 0,
            'iid' => $mrData['iid'] ?? null,
            'id' => $mrData['id'] ?? null,
            'title' => $mrData['title'] ?? '',
            'head' => ['ref' => $mrData['source_branch'] ?? ''],
            'base' => ['ref' => $mrData['target_branch'] ?? ''],
            'draft' => $mrData['work_in_progress'] ?? false,
            'state' => $this->mapStateFromGitLab($mrData['state'] ?? 'opened'),
            'body' => $mrData['description'] ?? '',
            'html_url' => $mrData['web_url'] ?? '',
            '_gitlab_data' => $mrData,
        ];
    }

    /**
     * Maps GitHub state to GitLab state.
     */
    public function mapStateToGitLab(string $githubState): string
    {
        return match ($githubState) {
            'open' => 'opened',
            'closed' => 'closed',
            default => $githubState,
        };
    }

    /**
     * Maps note rows (newest first) to chronological DTOs, keeping at most $limit rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return PullRequestComment[]
     */
    public function mapNotes(array $rows, int $limit): array
    {
        $comments = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $comments[] = $this->mapNote($row);
        }

        return array_reverse($comments);
    }

    /**
     * Maps positioned (diff) discussion notes to DTOs in reverse order, keeping at most $limit notes.
     *
     * @param array<int, array<string, mixed>> $discussions
     * @return PullRequestComment[]
     */
    public function mapDiscussions(array $discussions, int $limit): array
    {
        $comments = [];
        foreach ($discussions as $discussion) {
            $position = $discussion['position'] ?? null;
            if (! is_array($position)) {
                continue;
            }
            [$path, $line] = $this->extractPathAndLineFromPosition($position);
            foreach ($discussion['notes'] ?? [] as $note) {
                if (count($comments) >= $limit) {
                    break 2;
                }
                $comments[] = $this->mapNote($note, $path, $line);
            }
        }

        return array_reverse($comments);
    }

    /**
     * Maps GitLab state to GitHub state.
     */
    protected function mapStateFromGitLab(string $gitlabState): string
    {
        return match ($gitlabState) {
            'opened' => 'open',
            'closed', 'merged' => 'closed',
            default => $gitlabState,
        };
    }

    /**
     * @param array<string, mixed> $position
     * @return array{0: ?string, 1: ?int}
     */
    protected function extractPathAndLineFromPosition(array $position): array
    {
        $path = isset($position['new_path']) ? (string) $position['new_path'] : (isset($position['old_path']) ? (string) $position['old_path'] : null);
        $line = isset($position['new_line']) ? (int) $position['new_line'] : (isset($position['old_line']) ? (int) $position['old_line'] : null);

        return [$path, $line];
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function mapNote(array $row, ?string $path = null, ?int $line = null): PullRequestComment
    {
        $authorData = $row['author'] ?? [];
        $author = is_array($authorData) && isset($authorData['username']) ? (string) $authorData['username'] : 'unknown';
        $createdAt = isset($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : new \DateTimeImmutable();
        $body = isset($row['body']) ? (string) $row['body'] : '';

        return new PullRequestComment($author, $createdAt, $body, $path, $line);
    }
}
