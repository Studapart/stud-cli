<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Responder\ProjectsLabelsTable;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;

class ProjectsLabelsTableTest extends CommandTestCase
{
    public function testRenderWritesOneRowPerLabelWithGroupColumns(): void
    {
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
                [
                    'project.labels.column_group_id',
                    'project.labels.column_group_name',
                    'project.labels.column_label_id',
                    'project.labels.column_label_name',
                    'project.labels.column_color',
                ],
                [
                    ['group-1', 'Type', 'label-1', 'Bug', '#ff0000'],
                    ['group-1', 'Type', 'label-2', 'Feature', ''],
                    ['group-2', 'Area', 'label-3', 'CLI', ''],
                ],
            );
        $table = new ProjectsLabelsTable(new ResponderHelper($this->translationService), $logger);

        $table->render([
            [
                'id' => 'group-1',
                'name' => 'Type',
                'labels' => [
                    ['id' => 'label-1', 'name' => 'Bug', 'color' => '#ff0000'],
                    ['id' => 'label-2', 'name' => 'Feature'],
                ],
            ],
            [
                'id' => 'group-2',
                'name' => 'Area',
                'labels' => [
                    ['id' => 'label-3', 'name' => 'CLI'],
                ],
            ],
        ]);
    }
}
