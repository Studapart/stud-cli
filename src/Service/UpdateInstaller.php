<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
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
        return new PortableUpdateService(
            $repository,
            $this->oldVersionCleaner(),
            new ManagedSymlinkSwitcher(),
            $this->httpClient,
        );
    }

    public function pharActivator(): PharVersionActivator
    {
        return new PharVersionActivator(ManagedInstallPaths::fromEnvironment(), new ManagedSymlinkSwitcher());
    }

    /**
     * Asks once whether older versions should be removed later. Declining or a quiet run writes no marker.
     */
    public function requestCleanup(WorkflowEntryRecorder $recorder, bool $quiet): void
    {
        if ($quiet || ! $this->prompt->confirm(MessageRef::key('update.cleanup_prompt'), false)) {
            return;
        }

        if ($this->oldVersionCleaner()->markPending()) {
            $recorder->addNote(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.cleanup_scheduled'));

            return;
        }

        $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.cleanup_mark_failed'));
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

    public function replaceBinary(WorkflowEntryRecorder $recorder, string $tempFile, UpdateTarget $target): int
    {
        return $this->fileService->replaceBinary(
            $recorder,
            $tempFile,
            $this->resolveBinaryPath($target->binaryPath),
            $target->currentVersion,
        );
    }

    public function deleteTempFile(string $tempFile): void
    {
        try {
            $this->fileSystem->delete($tempFile);
        } catch (\RuntimeException) {
        }
    }

    private function oldVersionCleaner(): OldVersionCleaner
    {
        return new OldVersionCleaner(ManagedInstallPaths::fromEnvironment());
    }
}
