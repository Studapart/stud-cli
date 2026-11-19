<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;

/**
 * Swaps the running binary for a downloaded one, keeping a backup for rollback.
 *
 * Uses native PHP file functions (copy, rename, chmod) instead of Flysystem because:
 * 1. We're updating the binary that is currently executing
 * 2. On Linux, we cannot rename an executing file (EBUSY error)
 * 3. We need atomic operations that work with the running process
 * 4. Flysystem cannot handle this special case
 */
class BinarySwapper
{
    /**
     * Copies the current executable to $backupPath. copy() works even when the file is in use.
     */
    public function backup(WorkflowEntryRecorder $recorder, string $binaryPath, string $backupPath): bool
    {
        try {
            if (! @copy($binaryPath, $backupPath)) {
                $lastError = error_get_last();
                $errorMessage = $lastError['message'] ?? 'Unknown error during backup';

                throw new \RuntimeException($errorMessage);
            }
        } catch (\Exception $e) {
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_backup', ['error' => $e->getMessage()]));

            return false;
        }

        return true;
    }

    /**
     * Moves the new binary into place; restores the backup when activation fails.
     * The backup file is left behind for cleanup on next run.
     */
    public function activate(WorkflowEntryRecorder $recorder, string $tempFile, string $binaryPath, string $backupPath): bool
    {
        try {
            if (! @rename($tempFile, $binaryPath)) {
                $lastError = error_get_last();

                throw new \RuntimeException($lastError['message'] ?? 'Unknown error during rename');
            }
            if (! $this->markExecutable($binaryPath)) {
                $lastError = error_get_last();

                throw new \RuntimeException($lastError['message'] ?? 'Unknown error during chmod');
            }

            return true;
        } catch (\Exception $e) {
            if (@rename($backupPath, $binaryPath)) {
                $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_rollback', ['error' => $e->getMessage()]));

                return false;
            }

            $lastError = error_get_last();
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_rollback_failed', [
                'original_error' => $e->getMessage(),
                'rollback_error' => $lastError['message'] ?? 'Unknown error during rename',
                'backup_path' => $backupPath,
            ]));

            return false;
        }
    }

    /**
     * Sets the executable bit. Overridden in tests when the mode cannot be changed.
     */
    protected function markExecutable(string $path): bool
    {
        return @chmod($path, 0755);
    }
}
