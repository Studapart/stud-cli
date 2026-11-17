<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\AttachmentTransferLog;
use App\DTO\ItemUploadInput;
use App\DTO\MessageRef;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ItemUploadResponse;
use App\Service\FileSystem;
use App\Service\IssueTrackerPort;
use App\Service\ItemUploadPathValidator;

class ItemUploadHandler implements JiraAware, LinearAware
{
    private readonly ItemUploadPathValidator $pathValidator;

    public function __construct(
        FileSystem $fileSystem,
        private readonly IssueTrackerPort $provider,
    ) {
        $this->pathValidator = new ItemUploadPathValidator($fileSystem);
    }

    public function handle(ItemUploadInput $input): ItemUploadResponse
    {
        $key = $this->normalizeKey($input->issueKey);
        if ($key === '') {
            return ItemUploadResponse::fatal(MessageRef::key('item.upload.error_no_key'));
        }

        if ($input->filePaths === []) {
            return ItemUploadResponse::fatal(MessageRef::key('item.upload.error_no_files'));
        }

        $cwd = getcwd();
        // @codeCoverageIgnoreStart
        if ($cwd === false) {
            return ItemUploadResponse::fatal(MessageRef::key('item.upload.error_cwd'));
        }
        // @codeCoverageIgnoreEnd

        $log = new AttachmentTransferLog();
        foreach ($input->filePaths as $relPath) {
            $this->processOnePath($key, $relPath, $cwd, $log);
        }

        return ItemUploadResponse::result($log->files(), $log->errors());
    }

    private function processOnePath(
        string $issueKey,
        string $relPath,
        string $cwd,
        AttachmentTransferLog $log
    ): void {
        $trimmed = trim($relPath);
        if ($trimmed === '') {
            return;
        }

        $rejection = $this->pathValidator->rejectionFor($trimmed);
        if ($rejection !== null) {
            $log->addError($trimmed, $rejection);

            return;
        }

        try {
            $this->provider->uploadAttachment($issueKey, $this->joinUnderCwd($cwd, $trimmed));
            $log->addFile(basename(str_replace('\\', '/', $trimmed)), $trimmed);
        } catch (\Throwable $e) {
            $log->addError($trimmed, MessageRef::key('item.upload.error_file', ['error' => $e->getMessage()]));
        }
    }

    private function normalizeKey(string $issueKey): string
    {
        $t = trim($issueKey);

        return $t === '' ? '' : strtoupper($t);
    }

    private function joinUnderCwd(string $cwd, string $relativePath): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $rel = str_replace(['/', '\\'], $sep, $relativePath);

        return $cwd . $sep . $rel;
    }
}
