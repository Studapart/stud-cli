<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProjectsWorkflowNormalizer;
use PHPUnit\Framework\TestCase;

class ProjectsWorkflowNormalizerTest extends TestCase
{
    private ProjectsWorkflowNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ProjectsWorkflowNormalizer();
    }

    public function testFromJiraTransitionsMapsTargetStatus(): void
    {
        $workflows = $this->normalizer->fromJiraTransitions([
            ['id' => 11, 'name' => 'Start Progress', 'to' => ['name' => 'In Progress']],
        ]);

        $this->assertSame([
            [
                'id' => '11',
                'name' => 'Start Progress',
                'targetStatus' => 'In Progress',
                'provider' => 'jira',
            ],
        ], $workflows);
    }

    public function testFromLinearStatesMapsType(): void
    {
        $workflows = $this->normalizer->fromLinearStates([
            ['id' => 'state-1', 'name' => 'In Progress', 'type' => 'started'],
        ]);

        $this->assertSame([
            [
                'id' => 'state-1',
                'name' => 'In Progress',
                'type' => 'started',
                'provider' => 'linear',
            ],
        ], $workflows);
    }
}
