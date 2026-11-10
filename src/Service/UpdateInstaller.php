<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\Service\Prompt\PromptInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpdateInstaller
{
    public function __construct(
        private readonly UpdateFileService $fileService,
        private readonly PromptInterface $prompt,
        private readonly FileSystem $fileSystem,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public function portableService(UpdateRepositoryContext $repository): PortableUpdateService
    {
        return new PortableUpdateService($repository, $this->prompt, $this->httpClient);
    }

    /**
     * @param array<string, mixed> $pharAsset
     */
    public function verifyHash(WorkflowEntryRecorder $recorder, string $tempFile, array $pharAsset, bool $quiet): bool
    {
        return $this->fileService->verifyHash($recorder, $tempFile, $pharAsset, $quiet);
    }

    public function resolveBinaryPath(string $binaryPath): string
    {
        return $this->fileService->getBinaryPath($binaryPath);
    }

    public function replaceBinary(WorkflowEntryRecorder $recorder, string $tempFile, UpdateTarget $target, string $tagName): int
    {
        return $this->fileService->replaceBinary(
            $recorder,
            $tempFile,
            $this->resolveBinaryPath($target->binaryPath),
            $target->currentVersion,
            $tagName,
        );
    }

    public function deleteTempFile(string $tempFile): void
    {
        try {
            $this->fileSystem->delete($tempFile);
        } catch (\RuntimeException) {
        }
    }
}
