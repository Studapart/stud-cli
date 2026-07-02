<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\StateChange;
use App\Enum\WorkItemProvider;
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
                'provider' => WorkItemProvider::Jira->value,
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
                'provider' => WorkItemProvider::Linear->value,
            ],
        ], $workflows);
    }

    public function testFromStateChangesMapsJiraTargetStatus(): void
    {
        $workflows = $this->normalizer->fromStateChanges(
            [new StateChange('11', 'Start Progress', 'In Progress')],
            WorkItemProvider::Jira->value,
        );

        $this->assertSame([
            [
                'id' => '11',
                'name' => 'Start Progress',
                'provider' => WorkItemProvider::Jira->value,
                'targetStatus' => 'In Progress',
            ],
        ], $workflows);
    }
}
