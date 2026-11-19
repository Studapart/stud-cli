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
 * Renders the filename/path table shared by the attachment download and upload responders.
 */
class AttachmentFilesTable
{
    /**
     * @param array<string, mixed> $jiraConfig
     */
    public function __construct(
        private readonly ResponderHelper $helper,
        private readonly Logger $logger,
        private readonly array $jiraConfig,
    ) {
    }

    /**
     * @param list<array{filename: string, path: string}> $files
     */
    public function render(array $files, string $filenameColumnKey, string $pathColumnKey): void
    {
        $rows = [];
        foreach ($files as $row) {
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
                        new Column('filename', $filenameColumnKey, fn ($item) => $item->filename),
                        new Column('path', $pathColumnKey, fn ($item) => $item->path),
                    ]),
                ]
            ),
        ], $this->helper->translator, $this->helper->colorHelper);
        $viewConfig->render($rows, $this->logger, ['jiraConfig' => $this->jiraConfig]);
    }
}
