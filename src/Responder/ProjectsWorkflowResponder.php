<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ProjectsWorkflowResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectsWorkflowResponder
{
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    public function respond(SymfonyStyle $io, ProjectsWorkflowResponse $response, OutputFormat $format = OutputFormat::Cli): ?AgentJsonResponse
    {
        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        $this->helper->initSection($this->logger, 'project.workflow.section');

        if (! $response->isSuccess()) {
            return null;
        }

        if ($response->stateChanges === []) {
            foreach ($response->getWarnings() as $warning) {
                $this->logger->warning(
                    Logger::VERBOSITY_NORMAL,
                    $this->messageRenderer?->render($warning->message) ?? (string) $warning->message,
                );
            }

            return null;
        }

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

        $viewConfig->render($response->stateChanges, $this->logger);

        return null;
    }

    protected function respondJson(ProjectsWorkflowResponse $response): AgentJsonResponse
    {
        return AgentJsonResponse::fromResponse(
            $response,
            ['stateChanges' => $response->stateChanges],
            renderer: $this->messageRenderer,
        );
    }
}
