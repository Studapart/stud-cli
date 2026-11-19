<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Responder\AttachmentFilesTable;
use App\Service\Logger;
use App\Service\ResponderHelper;
use App\Tests\CommandTestCase;

class AttachmentFilesTableTest extends CommandTestCase
{
    public function testRenderWritesFilenameAndPathRowsUnderGivenHeaders(): void
    {
        /** @var Logger&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('table')
            ->with(
                Logger::VERBOSITY_NORMAL,
                ['item.download.col_filename', 'item.download.col_path'],
                [['a.txt', 'dir/a.txt'], ['b.png', 'dir/b.png']],
            );
        $table = new AttachmentFilesTable(new ResponderHelper($this->translationService), $logger, ['JIRA_URL' => 'https://jira.example']);

        $table->render(
            [
                ['filename' => 'a.txt', 'path' => 'dir/a.txt'],
                ['filename' => 'b.png', 'path' => 'dir/b.png'],
            ],
            'item.download.col_filename',
            'item.download.col_path',
        );
    }
}
