<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GithubPullRequestCommentMapper;
use PHPUnit\Framework\TestCase;

class GithubPullRequestCommentMapperTest extends TestCase
{
    private GithubPullRequestCommentMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new GithubPullRequestCommentMapper();
    }

    public function testMapCommentsReturnsChronologicalDtosWithinLimit(): void
    {
        $rows = [
            ['user' => ['login' => 'carol'], 'created_at' => '2026-01-03T00:00:00Z', 'body' => 'third', 'path' => 'src/A.php', 'line' => 12],
            ['user' => ['login' => 'bob'], 'created_at' => '2026-01-02T00:00:00Z', 'body' => 'second'],
            ['user' => ['login' => 'alice'], 'created_at' => '2026-01-01T00:00:00Z', 'body' => 'first'],
        ];

        $result = $this->mapper->mapComments($rows, 2);

        $this->assertCount(2, $result);
        $this->assertSame('bob', $result[0]->author);
        $this->assertNull($result[0]->path);
        $this->assertNull($result[0]->line);
        $this->assertSame('carol', $result[1]->author);
        $this->assertSame('third', $result[1]->body);
        $this->assertSame('src/A.php', $result[1]->path);
        $this->assertSame(12, $result[1]->line);
        $this->assertSame('2026-01-03', $result[1]->date->format('Y-m-d'));
    }

    public function testMapCommentsUsesDefaultsForMissingFields(): void
    {
        $result = $this->mapper->mapComments([['user' => 'not-an-array']], 50);

        $this->assertCount(1, $result);
        $this->assertSame('unknown', $result[0]->author);
        $this->assertSame('', $result[0]->body);
    }

    public function testMapReviewsSkipsEmptyBodiesAndReversesOrder(): void
    {
        $rows = [
            ['user' => ['login' => 'alice'], 'submitted_at' => '2026-01-01T00:00:00Z', 'body' => 'LGTM'],
            ['user' => ['login' => 'bob'], 'submitted_at' => '2026-01-02T00:00:00Z', 'body' => '   '],
            ['user' => ['login' => 'carol'], 'created_at' => '2026-01-03T00:00:00Z', 'body' => ' Needs work '],
        ];

        $result = $this->mapper->mapReviews($rows, 50);

        $this->assertCount(2, $result);
        $this->assertSame('carol', $result[0]->author);
        $this->assertSame('Needs work', $result[0]->body);
        $this->assertSame('2026-01-03', $result[0]->date->format('Y-m-d'));
        $this->assertNull($result[0]->path);
        $this->assertSame('alice', $result[1]->author);
    }

    public function testMapReviewsStopsAtLimit(): void
    {
        $rows = [
            ['user' => ['login' => 'alice'], 'submitted_at' => '2026-01-01T00:00:00Z', 'body' => 'one'],
            ['body' => 'two'],
            ['user' => ['login' => 'carol'], 'submitted_at' => '2026-01-03T00:00:00Z', 'body' => 'three'],
        ];

        $result = $this->mapper->mapReviews($rows, 2);

        $this->assertCount(2, $result);
        $this->assertSame('unknown', $result[0]->author);
        $this->assertSame('alice', $result[1]->author);
    }
}
