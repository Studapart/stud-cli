<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ProjectsWorkflowResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectsWorkflowResponder
{
    private readonly ProjectsWorkflowTable $table;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
        $this->table = new ProjectsWorkflowTable($helper, $logger);
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

        $this->table->render($response->stateChanges);

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
