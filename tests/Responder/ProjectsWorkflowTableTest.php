<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Responder\ProjectsWorkflowTable;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;

class ProjectsWorkflowTableTest extends CommandTestCase
{
    public function testRenderWritesStateChangesAndBlanksMissingOptionalColumns(): void
    {
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
                [
                    'project.workflow.column_id',
                    'project.workflow.column_name',
                    'project.workflow.column_target_status',
                    'project.workflow.column_type',
                    'project.workflow.column_provider',
                ],
                [
                    ['11', 'Start', 'In Progress', '', 'jira'],
                    ['st-1', 'Done', '', 'completed', 'linear'],
                ],
            );
        $table = new ProjectsWorkflowTable(new ResponderHelper($this->translationService), $logger);

        $table->render([
            ['id' => '11', 'name' => 'Start', 'targetStatus' => 'In Progress', 'provider' => 'jira'],
            ['id' => 'st-1', 'name' => 'Done', 'type' => 'completed', 'provider' => 'linear'],
        ]);
    }
}
