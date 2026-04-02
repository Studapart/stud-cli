<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\ItemUploadInput;
use App\Exception\ApiException;
use App\Response\ItemUploadResponse;
use App\Service\FileSystem;
use App\Service\JiraAttachmentService;
use App\Service\TranslationService;

class ItemUploadHandler
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly JiraAttachmentService $jiraAttachmentService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(ItemUploadInput $input): ItemUploadResponse
    {
        $key = $this->normalizeKey($input->issueKey);
        if ($key === '') {
            return ItemUploadResponse::fatal($this->translator->trans('item.upload.error_no_key'));
        }

        if ($input->filePaths === []) {
            return ItemUploadResponse::fatal($this->translator->trans('item.upload.error_no_files'));
        }

        $cwd = getcwd();
        // @codeCoverageIgnoreStart
        if ($cwd === false) {
            return ItemUploadResponse::fatal($this->translator->trans('item.upload.error_cwd'));
        }
        // @codeCoverageIgnoreEnd

        $files = [];
        $errors = [];
        foreach ($input->filePaths as $relPath) {
            $this->processOnePath($key, $relPath, $cwd, $files, $errors);
        }

        return ItemUploadResponse::result($files, $errors);
    }

    /**
     * @param list<array{filename: string, path: string}>             $files
     * @param list<array{filename: string|null, message: string}> $errors
     */
    private function processOnePath(
        string $issueKey,
        string $relPath,
        string $cwd,
        array &$files,
        array &$errors
    ): void {
        $trimmed = trim($relPath);
        if ($trimmed === '') {
            return;
        }

        try {
            $this->assertNoPathTraversal($trimmed);
        } catch (\InvalidArgumentException) {
            $errors[] = [
                'filename' => $trimmed,
                'message' => $this->translator->trans('item.upload.error_path_traversal'),
            ];

            return;
        }

        if (! $this->fileSystem->fileExists($trimmed)) {
            $errors[] = [
                'filename' => $trimmed,
                'message' => $this->translator->trans('item.upload.error_not_found'),
            ];

            return;
        }

        if ($this->fileSystem->isDir($trimmed)) {
            $errors[] = [
                'filename' => $trimmed,
                'message' => $this->translator->trans('item.upload.error_not_file'),
            ];

            return;
        }

        $absolute = $this->joinUnderCwd($cwd, $trimmed);
        $uploadName = basename(str_replace('\\', '/', $trimmed));

        try {
            $this->jiraAttachmentService->uploadFileToIssue($issueKey, $absolute);
            $files[] = ['filename' => $uploadName, 'path' => $trimmed];
        } catch (ApiException $e) {
            $detail = $e->getTechnicalDetails();
            $message = $detail !== '' ? $e->getMessage() . ' ' . $detail : $e->getMessage();
            $errors[] = ['filename' => $trimmed, 'message' => $message];
        } catch (\Throwable $e) {
            $errors[] = ['filename' => $trimmed, 'message' => $e->getMessage()];
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
