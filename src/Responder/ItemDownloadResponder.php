<?php

declare(strict_types=1);

namespace App\Responder;

use App\Enum\OutputFormat;
use App\Response\AgentJsonResponse;
use App\Response\ItemDownloadResponse;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\View\Column;
use App\View\PageViewConfig;
use App\View\Section;
use App\View\TableBlock;
use Symfony\Component\Console\Style\SymfonyStyle;

class ItemDownloadResponder
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
        ItemDownloadResponse $response,
        OutputFormat $format = OutputFormat::Cli,
    ): ?AgentJsonResponse {
        // IO is required by Castor task signature; output goes through Logger.
        unset($io);

        if ($format === OutputFormat::Json) {
            return $this->respondJson($response);
        }

        if (! $response->isSuccess()) {
            return null;
        }

        $this->helper->initSection($this->logger, 'item.download.section');

        if ($response->files === []) {
            $this->logger->note(Logger::VERBOSITY_NORMAL, $this->helper->translator->trans('item.download.none'));
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
                            new Column('filename', 'item.download.col_filename', fn ($item) => $item->filename),
                            new Column('path', 'item.download.col_path', fn ($item) => $item->path),
                        ]),
                    ]
                ),
            ], $this->helper->translator, $this->helper->colorHelper);
            $viewConfig->render($rows, $this->logger, ['jiraConfig' => $this->jiraConfig]);
        }

        $this->renderPerFileErrors($response);

        return null;
    }

    protected function respondJson(ItemDownloadResponse $response): AgentJsonResponse
    {
        if (! $response->isSuccess()) {
            return new AgentJsonResponse(false, error: $response->getError() ?? 'Unknown error');
        }

        return new AgentJsonResponse(true, data: [
            'files' => $response->files,
            'errors' => $response->errors,
        ]);
    }

    protected function renderPerFileErrors(ItemDownloadResponse $response): void
    {
        foreach ($response->errors as $err) {
            $this->logger->error(
                Logger::VERBOSITY_NORMAL,
                $this->helper->translator->trans('item.download.error_partial', [
                    'filename' => $err['filename'] ?? '',
                    'message' => $err['message'],
                ])
            );
        }
    }
}
