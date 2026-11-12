<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\AttachmentTransferLog;
use App\DTO\ItemUploadInput;
use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ItemUploadResponse;
use App\Service\FileSystem;
use App\Service\IssueTrackerPort;

class ItemUploadHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly IssueTrackerPort $provider,
    ) {
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

        try {
            $this->assertNoPathTraversal($trimmed);
        } catch (\InvalidArgumentException) {
            $log->addError($trimmed, MessageRef::key('item.upload.error_path_traversal'));

            return;
        }

        if (! $this->fileSystem->fileExists($trimmed)) {
            $log->addError($trimmed, MessageRef::key('item.upload.error_not_found'));

            return;
        }

        if ($this->fileSystem->isDir($trimmed)) {
            $log->addError($trimmed, MessageRef::key('item.upload.error_not_file'));

            return;
        }

        $absolute = $this->joinUnderCwd($cwd, $trimmed);
        $uploadName = basename(str_replace('\\', '/', $trimmed));

        try {
            $this->provider->uploadAttachment($issueKey, $absolute);
            $log->addFile($uploadName, $trimmed);
        } catch (ApiException $e) {
            $log->addError($trimmed, MessageRef::key('item.upload.error_file', ['error' => $e->getMessage()]));
        } catch (\Throwable $e) {
            $log->addError($trimmed, MessageRef::key('item.upload.error_file', ['error' => $e->getMessage()]));
        }
    }

    private function normalizeKey(string $issueKey): string
    {
        $t = trim($issueKey);

        return $t === '' ? '' : strtoupper($t);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertNoPathTraversal(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('item.upload.error_path_traversal');
            }
        }
    }

    private function joinUnderCwd(string $cwd, string $relativePath): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $rel = str_replace(['/', '\\'], $sep, $relativePath);

        return $cwd . $sep . $rel;
    }
}
