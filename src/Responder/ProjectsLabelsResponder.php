<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ProjectsLabelsResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectsLabelsResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    public function respond(SymfonyStyle $io, ProjectsLabelsResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'project.labels.section');

        if (! $response->isSuccess()) {
            return null;
        }

        foreach ($response->getNotices() as $notice) {
            $this->logger->note(
                Logger::VERBOSITY_NORMAL,
                $this->messageRenderer?->render($notice->message) ?? (string) $notice->message,
            );
        }

        if ($response->groups === []) {
            foreach ($response->getWarnings() as $warning) {
                $this->logger->warning(
                    Logger::VERBOSITY_NORMAL,
                    $this->messageRenderer?->render($warning->message) ?? (string) $warning->message,
                );
            }

            return null;
        }

        $rows = $this->flattenGroupsForTable($response->groups);

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

        $viewConfig->render($rows, $this->logger);

        return null;
    }

    /**
     * @param list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}> $groups
     * @return list<array{groupId: string, groupName: string, id: string, name: string, color: string}>
     */
    protected function flattenGroupsForTable(array $groups): array
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

    protected function respondJson(ProjectsLabelsResponse $response): AgentJsonResponse
    {
        return AgentJsonResponse::fromResponse(
            $response,
            ['groups' => $response->groups],
            renderer: $this->messageRenderer,
        );
    }
}
