<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\WorkflowOutputEntry;
use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\WorkflowResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;
use Symfony\Component\Console\Style\SymfonyStyle;

class WorkflowResponder
{
    public function __construct(
        private readonly Logger $logger,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    public function respond(
        SymfonyStyle $io,
        WorkflowResponse $response,
        OutputFormat $format = OutputFormat::Cli,
        bool $compact = true,
    ): ?AgentJsonResponse {
        if ($format === OutputFormat::Json) {
            if ($compact && $response->isSuccess()) {
                return AgentJsonResponse::successWithoutData($response->diagnosticsPayload($this->messageRenderer));
            }

            return AgentJsonResponse::fromResponse(
                $response,
                [
                    'exitCode' => $response->exitCode,
                ],
                false,
                $this->messageRenderer,
            );
        }

        foreach ($response->entries as $entry) {
            $this->renderEntry($entry);
        }

        return null;
    }

    private function renderEntry(WorkflowOutputEntry $entry): void
    {
        match ($entry->type) {
            'error' => $this->logger->error($entry->verbosity, $this->message($entry)),
            'errorWithDetails' => $this->logger->errorWithDetails($entry->verbosity, $this->renderedString($entry), $entry->technicalDetails ?? ''),
            'warning' => $this->logger->warning($entry->verbosity, $this->message($entry)),
            'note' => $this->logger->note($entry->verbosity, $this->message($entry)),
            'success' => $this->logger->success($entry->verbosity, $this->message($entry)),
            'text' => $this->logger->text($entry->verbosity, $this->message($entry)),
            'rawValue' => $this->logger->rawValue($this->renderedString($entry)),
            'writeln' => $this->logger->writeln($entry->verbosity, $this->renderedString($entry)),
            'jiraWriteln' => $this->logger->jiraWriteln($entry->verbosity, $this->renderedString($entry)),
            'gitWriteln' => $this->logger->gitWriteln($entry->verbosity, $this->renderedString($entry)),
            'jiraText' => $this->logger->jiraText($entry->verbosity, $this->stringMessage($entry)),
            'gitText' => $this->logger->gitText($entry->verbosity, $this->stringMessage($entry)),
            'section' => $this->logger->section($entry->verbosity, $this->renderedString($entry)),
            'title' => $this->logger->title($entry->verbosity, $this->renderedString($entry)),
            'listing' => $this->logger->listing($entry->verbosity, $entry->elements),
            'comment' => $this->logger->comment($entry->verbosity, $this->stringMessage($entry)),
            'info' => $this->logger->info($entry->verbosity, $this->stringMessage($entry)),
            'caution' => $this->logger->caution($entry->verbosity, $this->stringMessage($entry)),
            'table' => $this->logger->table($entry->verbosity, $entry->headers, $entry->rows),
            'horizontalTable' => $this->logger->horizontalTable($entry->verbosity, $entry->headers, $entry->rows),
            'definitionList' => $this->logger->definitionList($entry->verbosity, ...$entry->definitionList),
            'newLine' => $this->logger->newLine($entry->verbosity, $entry->count),
            default => null,
        };
    }

    /**
     * @return \App\DTO\MessageRef|string|array<\App\DTO\MessageRef|string>
     */
    private function message(WorkflowOutputEntry $entry): \App\DTO\MessageRef|string|array
    {
        return $entry->message ?? '';
    }

    /**
     * @return string|array<string>
     */
    private function stringMessage(WorkflowOutputEntry $entry): string|array
    {
        $message = $this->message($entry);
        if (is_array($message)) {
            return array_map(fn ($line): string => $this->messageRenderer?->render($line) ?? (string) $line, $message);
        }

        return $this->messageRenderer?->render($message) ?? (string) $message;
    }

    private function renderedString(WorkflowOutputEntry $entry): string
    {
        $message = $this->stringMessage($entry);

        return is_array($message) ? implode("\n", $message) : $message;
    }
}
