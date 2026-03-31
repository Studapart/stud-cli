<?php

declare(strict_types=1);

namespace App\Handler;

use App\Exception\ApiException;
use App\Response\ItemDownloadResponse;
use App\Service\FileSystem;
use App\Service\JiraAttachmentService;
use App\Service\TranslationService;

class ItemDownloadHandler
{
    private const DEFAULT_RELATIVE_DIR = '.cursor/stud-downloads';

    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly JiraAttachmentService $jiraAttachmentService,
        private readonly TranslationService $translator
    ) {
    }

    /**
     * @param string|null $issueKey Non-empty: download all attachments (ignores $url)
     * @param string|null $url      Required when $issueKey is empty
     * @param string|null $path     Target directory relative to cwd, or empty for default
     */
    public function handle(?string $issueKey, ?string $url, ?string $path): ItemDownloadResponse
    {
        $key = $this->normalizeKey($issueKey);
        $urlTrim = $this->normalizeUrl($url);

        if ($key === '' && $urlTrim === '') {
            return ItemDownloadResponse::fatal(
                $this->translator->trans('item.download.error_key_or_url')
            );
        }

        try {
            $targetDir = $this->resolveTargetDirectory($path);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $message = $e->getMessage();
            if ($message === 'item.download.error_path_traversal') {
                $message = $this->translator->trans($message);
            }

            return ItemDownloadResponse::fatal($message);
        }

        if ($key !== '') {
            return $this->downloadAllForIssue($key, $targetDir);
        }

        return $this->downloadSingleFromUrl($urlTrim, $targetDir);
    }

    private function normalizeKey(?string $issueKey): string
    {
        if ($issueKey === null || trim($issueKey) === '') {
            return '';
        }

        return strtoupper(trim($issueKey));
    }

    private function normalizeUrl(?string $url): string
    {
        if ($url === null) {
            return '';
        }

        return trim($url);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function resolveTargetDirectory(?string $pathOption): string
    {
        $cwd = getcwd();
        // @codeCoverageIgnoreStart
        if ($cwd === false) {
            throw new \RuntimeException('Cannot determine current working directory.');
        }
        // @codeCoverageIgnoreEnd

        $rel = ($pathOption !== null && trim($pathOption) !== '') ? trim($pathOption) : self::DEFAULT_RELATIVE_DIR;
        $this->assertNoPathTraversal($rel);
        $this->fileSystem->mkdir($rel, 0777, true);

        return $rel;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertNoPathTraversal(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new \InvalidArgumentException('item.download.error_path_traversal');
            }
        }
    }

    private function downloadAllForIssue(string $issueKey, string $targetDir): ItemDownloadResponse
    {
        try {
            $attachments = $this->jiraAttachmentService->fetchAttachmentsForIssue($issueKey);
        } catch (ApiException $e) {
            return ItemDownloadResponse::fatal($e->getMessage());
        }

        $files = [];
        $errors = [];
        foreach ($attachments as $attachment) {
            $files = $this->pullOneAttachment($attachment['filename'], $attachment['contentUrl'], $targetDir, $files, $errors);
        }

        return ItemDownloadResponse::result($files, $errors);
    }

    private function downloadSingleFromUrl(string $url, string $targetDir): ItemDownloadResponse
    {
        $filename = $this->filenameHintFromUrl($url);
        $files = [];
        $errors = [];
        $files = $this->pullOneAttachment($filename, $url, $targetDir, $files, $errors);

        if ($files === [] && $errors !== []) {
            $first = $errors[0]['message'] ?? 'Download failed.';

            return ItemDownloadResponse::fatal((string) $first);
        }

        return ItemDownloadResponse::result($files, $errors);
    }

    /**
     * @param list<array{filename: string, path: string}>             $files
     * @param list<array{filename: string|null, message: string}> $errors
     * @return list<array{filename: string, path: string}>
     */
    private function pullOneAttachment(
        string $originalFilename,
        string $contentUrl,
        string $targetDir,
        array $files,
        array &$errors
    ): array {
        try {
            $body = $this->jiraAttachmentService->downloadAttachmentContent($contentUrl);
        } catch (ApiException $e) {
            $errors[] = ['filename' => $originalFilename, 'message' => $e->getMessage()];

            return $files;
        } catch (\Throwable $e) {
            $errors[] = ['filename' => $originalFilename, 'message' => $e->getMessage()];

            return $files;
        }

        $safe = $this->sanitizeFilename($originalFilename);
        $uniqueName = $this->allocateFilename($targetDir, $safe);
        $relativePath = $targetDir . '/' . $uniqueName;

        try {
            $this->fileSystem->filePutContents($relativePath, $body);
        } catch (\Throwable $e) {
            $errors[] = ['filename' => $originalFilename, 'message' => $e->getMessage()];

            return $files;
        }

        $files[] = ['filename' => $uniqueName, 'path' => $relativePath];

        return $files;
    }

    private function filenameHintFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return 'attachment';
        }

        $base = basename($path);
        if ($base === '' || $base === '/' || $base === '.' || $base === '..') {
            return 'attachment';
        }

        return $base;
    }

    protected function sanitizeFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?? 'attachment';
        if ($sanitized === '' || $sanitized === '.' || $sanitized === '..') {
            return 'attachment';
        }

        return $sanitized;
    }

    protected function allocateFilename(string $dir, string $sanitizedName): string
    {
        $candidate = $sanitizedName;
        if (! $this->fileSystem->fileExists($dir . '/' . $candidate)) {
            return $candidate;
        }

        $info = pathinfo($sanitizedName);
        $stem = $info['filename'] !== '' ? $info['filename'] : 'attachment';
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

        for ($i = 1; $i < 1000; $i++) {
            $try = "{$stem}_{$i}{$ext}";
            if (! $this->fileSystem->fileExists($dir . '/' . $try)) {
                return $try;
            }
        }

        return $stem . '_' . bin2hex(random_bytes(4)) . $ext;
    }
}
