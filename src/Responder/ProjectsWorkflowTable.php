<?php

declare(strict_types=1);

namespace App\Responder;

use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;

/**
 * Renders project workflow state changes as a table, blanking optional target status and type.
 */
class ProjectsWorkflowTable
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $stateChanges
     */
    public function render(array $stateChanges): void
    {
        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('id', 'project.workflow.column_id', fn (array $item) => $item['id']),
                        new Column('name', 'project.workflow.column_name', fn (array $item) => $item['name']),
                        new Column('targetStatus', 'project.workflow.column_target_status', fn (array $item) => $item['targetStatus'] ?? ''),
                        new Column('type', 'project.workflow.column_type', fn (array $item) => $item['type'] ?? ''),
                        new Column('provider', 'project.workflow.column_provider', fn (array $item) => $item['provider']),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($stateChanges, $this->logger);
    }
}
