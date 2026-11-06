<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitLabMergeRequestMapper;
use PHPUnit\Framework\TestCase;

class GitLabMergeRequestMapperTest extends TestCase
{
    private GitLabMergeRequestMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new GitLabMergeRequestMapper();
    }

    public function testNormalizeMergeRequestData(): void
    {
        $gitlabMr = [
            'iid' => 123,
            'id' => 456,
            'title' => 'Test MR',
            'source_branch' => 'feature/test',
            'target_branch' => 'develop',
            'work_in_progress' => true,
            'state' => 'opened',
            'description' => 'Test description',
            'web_url' => 'https://gitlab.com/test_owner/test_repo/-/merge_requests/123',
        ];

        $result = $this->mapper->normalizeMergeRequestData($gitlabMr);

        $this->assertSame(123, $result['number']);
        $this->assertSame('Test MR', $result['title']);
        $this->assertSame('feature/test', $result['head']['ref']);
        $this->assertSame('develop', $result['base']['ref']);
        $this->assertTrue($result['draft']);
        $this->assertSame('open', $result['state']);
        $this->assertSame('Test description', $result['body']);
        $this->assertSame('https://gitlab.com/test_owner/test_repo/-/merge_requests/123', $result['html_url']);
        $this->assertSame($gitlabMr, $result['_gitlab_data']);
    }

    public function testNormalizeMergeRequestDataMapsMergedAndUnknownStates(): void
    {
        $this->assertSame('closed', $this->mapper->normalizeMergeRequestData(['state' => 'merged'])['state']);
        $this->assertSame('closed', $this->mapper->normalizeMergeRequestData(['state' => 'closed'])['state']);
        $this->assertSame('locked', $this->mapper->normalizeMergeRequestData(['state' => 'locked'])['state']);
        $this->assertSame('open', $this->mapper->normalizeMergeRequestData([])['state']);
    }

    public function testMapStateToGitLab(): void
    {
        $this->assertSame('opened', $this->mapper->mapStateToGitLab('open'));
        $this->assertSame('closed', $this->mapper->mapStateToGitLab('closed'));
        $this->assertSame('all', $this->mapper->mapStateToGitLab('all'));
    }

    public function testMapNotesReturnsChronologicalDtosWithinLimit(): void
    {
        $rows = [
            ['author' => ['username' => 'carol'], 'created_at' => '2026-01-03T00:00:00Z', 'body' => 'third'],
            ['author' => ['username' => 'bob'], 'created_at' => '2026-01-02T00:00:00Z', 'body' => 'second'],
            ['author' => 'not-an-array'],
        ];

        $result = $this->mapper->mapNotes($rows, 2);

        $this->assertCount(2, $result);
        $this->assertSame('bob', $result[0]->author);
        $this->assertSame('carol', $result[1]->author);
        $this->assertSame('third', $result[1]->body);
        $this->assertNull($result[1]->path);
        $this->assertSame('2026-01-03', $result[1]->date->format('Y-m-d'));
    }

    public function testMapNotesUsesDefaultsForMissingFields(): void
    {
        $result = $this->mapper->mapNotes([['author' => 'not-an-array']], 50);

        $this->assertSame('unknown', $result[0]->author);
        $this->assertSame('', $result[0]->body);
    }

    public function testMapDiscussionsSkipsUnpositionedDiscussionsAndUsesPosition(): void
    {
        $discussions = [
            ['notes' => [['author' => ['username' => 'skipped'], 'body' => 'general']]],
            [
                'position' => ['new_path' => 'src/A.php', 'new_line' => 10],
                'notes' => [['author' => ['username' => 'alice'], 'created_at' => '2026-01-01T00:00:00Z', 'body' => 'first']],
            ],
            [
                'position' => ['old_path' => 'src/B.php', 'old_line' => 4],
                'notes' => [['author' => ['username' => 'bob'], 'created_at' => '2026-01-02T00:00:00Z', 'body' => 'second']],
            ],
            ['position' => [], 'notes' => [['author' => ['username' => 'carol'], 'body' => 'third']]],
        ];

        $result = $this->mapper->mapDiscussions($discussions, 50);

        $this->assertCount(3, $result);
        $this->assertSame('carol', $result[0]->author);
        $this->assertNull($result[0]->path);
        $this->assertNull($result[0]->line);
        $this->assertSame('bob', $result[1]->author);
        $this->assertSame('src/B.php', $result[1]->path);
        $this->assertSame(4, $result[1]->line);
        $this->assertSame('alice', $result[2]->author);
        $this->assertSame('src/A.php', $result[2]->path);
        $this->assertSame(10, $result[2]->line);
    }

    public function testMapDiscussionsStopsAtLimit(): void
    {
        $discussions = [
            [
                'position' => ['new_path' => 'src/A.php', 'new_line' => 1],
                'notes' => [
                    ['author' => ['username' => 'alice'], 'body' => 'one'],
                    ['author' => ['username' => 'bob'], 'body' => 'two'],
                ],
            ],
            [
                'position' => ['new_path' => 'src/B.php', 'new_line' => 2],
                'notes' => [['author' => ['username' => 'carol'], 'body' => 'three']],
            ],
        ];

        $result = $this->mapper->mapDiscussions($discussions, 1);

        $this->assertCount(1, $result);
        $this->assertSame('alice', $result[0]->author);
    }
}
