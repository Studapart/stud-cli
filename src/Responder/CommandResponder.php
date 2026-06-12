<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\ResponseMessage;
use App\Enum\OutputFormat;
use App\Enum\ResponseMessageLevel;
use App\Response\AgentJsonResponse;
use App\Response\CommandResponse;
use App\Service\Logger;
use App\Service\MessageRenderer;

final class CommandResponder
{
    public function __construct(
        private readonly Logger $logger,
        private readonly ?MessageRenderer $messageRenderer = null,
    ) {
    }

    public function respond(
        CommandResponse $response,
        OutputFormat $format = OutputFormat::Cli,
        bool $compact = false,
    ): ?AgentJsonResponse {
        if ($format === OutputFormat::Json) {
            return (new AgentCommandResponder($this->messageRenderer))->respond($response, $compact);
        }

        $this->renderCli($response);

        return null;
    }

    private function renderCli(CommandResponse $response): void
    {
        foreach ($response->getMessages() as $message) {
            $this->renderMessage($message);
        }

        if (! $response->isSuccess()) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                $this->render($response->getErrorMessage()) ?? 'Unknown error',
            );

            return;
        }

        if ($response->message !== null) {
            $this->logger->success(Logger::VERBOSITY_NORMAL, $this->render($response->message) ?? '');
        }
    }

    private function renderMessage(ResponseMessage $message): void
    {
        match ($message->level) {
            ResponseMessageLevel::Error => $this->logger->errorWithDetails(
                Logger::VERBOSITY_NORMAL,
                $this->render($message->message) ?? '',
                $message->technicalDetails ?? '',
            ),
            ResponseMessageLevel::Warning => $this->logger->warning(Logger::VERBOSITY_NORMAL, $this->render($message->message) ?? ''),
            ResponseMessageLevel::Notice => $this->logger->note(Logger::VERBOSITY_NORMAL, $this->render($message->message) ?? ''),
            ResponseMessageLevel::Info => $this->logger->text(Logger::VERBOSITY_VERBOSE, $this->render($message->message) ?? ''),
        };
    }

    private function render(string|\App\DTO\MessageRef|null $message): ?string
    {
        return $this->messageRenderer?->render($message) ?? ($message === null ? null : (string) $message);
    }
}
