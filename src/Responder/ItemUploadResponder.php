<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemUploadResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemUploadResponder
{
    private readonly AttachmentFilesTable $filesTable;

    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        array $jiraConfig,
        private readonly Logger $logger,
    ) {
        $this->filesTable = new AttachmentFilesTable($helper, $logger, $jiraConfig);
    }

    public function respond(
        SymfonyStyle $io,
        ItemUploadResponse $response,
        OutputFormat $format = OutputFormat::Cli,
    ): ?AgentJsonResponse {
        unset($io);

        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        if (! $response->isSuccess()) {
            return null;
        }

        $this->helper->initSection($this->logger, 'item.upload.section');

        if ($response->files === []) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('item.upload.none'));
        } else {
            $this->filesTable->render($response->files, 'item.upload.col_filename', 'item.upload.col_path');
        }

        $this->renderPerFileErrors($response);

        return null;
    }

    protected function respondJson(ItemUploadResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $this->helper->translator->renderForAgentText($response->getErrorMessage()));
        }

        return new AgentJsonResponse(true, data: [
            'files' => $response->files,
            'errors' => $this->renderErrors($response->errors),
        ]);
    }

    protected function renderPerFileErrors(ItemUploadResponse $response): void
    {
        foreach ($response->errors as $err) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                $this->helper->translator->trans('item.upload.error_partial', [
                    'filename' => $err['filename'] ?? '',
                    'message' => $this->helper->translator->renderText($err['message']),
                ])
            );
        }
    }

    /**
     * @param list<array{filename: string|null, message: mixed}> $errors
     * @return list<array{filename: string|null, message: string}>
     */
    private function renderErrors(array $errors): array
    {
        return array_map(fn (array $error): array => [
            'filename' => $error['filename'],
            'message' => $this->helper->translator->renderForAgentText($error['message']),
        ], $errors);
    }
}
