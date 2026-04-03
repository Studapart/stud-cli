<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemUploadResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemUploadResponder
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly array $jiraConfig,
        private readonly Logger $logger,
    ) {
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
            $rows = [];
            foreach ($response->files as $row) {
                $obj = new \stdClass();
                $obj->filename = $row['filename'];
                $obj->path = $row['path'];
                $rows[] = $obj;
            }

            $viewConfig = new PageViewConfig([
                new Section(
                    '',
                    [
                        new TableBlock([
                            new Column('filename', 'item.upload.col_filename', fn ($item) => $item->filename),
                            new Column('path', 'item.upload.col_path', fn ($item) => $item->path),
                        ]),
                    ]
                ),
            ], $this->helper->translator, $this->helper->colorHelper);
            $viewConfig->render($rows, $this->logger, ['jiraConfig' => $this->jiraConfig]);
        }

        $this->renderPerFileErrors($response);

        return null;
    }

    protected function respondJson(ItemUploadResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'files' => $response->files,
            'errors' => $response->errors,
        ]);
    }

    protected function renderPerFileErrors(ItemUploadResponse $response): void
    {
        foreach ($response->errors as $err) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                $this->helper->translator->trans('item.upload.error_partial', [
                    'filename' => $err['filename'] ?? '',
                    'message' => $err['message'],
                ])
            );
        }
    }
}
