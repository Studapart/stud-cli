<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ProjectsLabelsResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectsLabelsResponder
{
    private readonly ProjectsLabelsTable $table;

    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
        $this->table = new ProjectsLabelsTable($helper, $logger);
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

        $this->table->render($response->groups);

        return null;
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
