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
 * Renders project label groups as one flat table row per label.
 */
class ProjectsLabelsTable
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}> $groups
     */
    public function render(array $groups): void
    {
        $viewConfig = new PageViewConfig([
            new Section(
                '',
                [
                    new TableBlock([
                        new Column('groupId', 'project.labels.column_group_id', fn (array $item) => $item['groupId']),
                        new Column('groupName', 'project.labels.column_group_name', fn (array $item) => $item['groupName']),
                        new Column('id', 'project.labels.column_label_id', fn (array $item) => $item['id']),
                        new Column('name', 'project.labels.column_label_name', fn (array $item) => $item['name']),
                        new Column('color', 'project.labels.column_color', fn (array $item) => $item['color'] ?? ''),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);

        $viewConfig->render($this->flattenGroups($groups), $this->logger);
    }

    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}> $groups
     * @return list<array{groupId: string, groupName: string, id: string, name: string, color: string}>
     */
    protected function flattenGroups(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group['labels'] as $label) {
                $rows[] = [
                    'groupId' => $group['id'],
                    'groupName' => $group['name'],
                    'id' => $label['id'],
                    'name' => $label['name'],
                    'color' => $label['color'] ?? '',
                ];
            }
        }

        return $rows;
    }
}
