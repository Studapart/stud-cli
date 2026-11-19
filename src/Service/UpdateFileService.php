<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\Service\Prompt\PromptInterface;

class UpdateFileService
{
    private readonly BinarySwapper $swapper;

    public function __construct(
        mixed $translator,
        private readonly PromptInterface $prompt,
    ) {
        unset($translator);
        $this->swapper = new BinarySwapper();
    }

    /**
     * Replaces the running binary with a new version, keeping a versioned backup
     * (e.g. /home/pem/.local/bin/stud-1.1.1.bak).
     *
     * No success() call on completion to avoid zlib error after PHAR replacement.
     */
    public function replaceBinary(WorkflowEntryRecorder $recorder, string $tempFile, string $binaryPath, string $currentVersion): int
    {
        $backupPath = $binaryPath . '-' . $currentVersion . '.bak';

        if (! is_writable($binaryPath)) {
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_not_writable'));
        } elseif ($this->swapper->backup($recorder, $binaryPath, $backupPath)
            && $this->swapper->activate($recorder, $tempFile, $binaryPath, $backupPath)) {
            return 0;
        }
        @unlink($tempFile);

        return 1;
    }

    public function getBinaryPath(string $binaryPath): string
    {
        // If running as PHAR, use Phar::running()
        // Hard to test in unit tests without actual PHAR environment
        // @codeCoverageIgnoreStart
        if (class_exists('Phar') && \Phar::running(false)) {
            return \Phar::running(false);
        }
        // @codeCoverageIgnoreEnd

        // Otherwise, try to get path from ReflectionClass as suggested in ticket
        try {
            $reflection = new \ReflectionClass(\Castor\Console\Application::class);
            $filename = $reflection->getFileName();

            // If we're in a PHAR, the filename will be phar://...
            // Hard to test in unit tests without actual PHAR environment
            // @codeCoverageIgnoreStart
            if ($filename !== false && str_starts_with($filename, 'phar://')) {
                return $filename;
            }
            // @codeCoverageIgnoreEnd
            // ReflectionException is hard to trigger in tests
            // @codeCoverageIgnoreStart
        } catch (\ReflectionException $e) {
            // Fall through to next method
        }
        // @codeCoverageIgnoreEnd

        // Fallback: use the provided binary path
        return $binaryPath;
    }

    /**
     * Verifies the hash of the downloaded file against the digest from GitHub API.
     * Returns true if verification succeeds or user overrides, false if user aborts (or quiet and mismatch).
     *
     * @param array<string, mixed> $pharAsset
     * @param bool $quiet When true, on verification failure do not prompt; return false (abort)
     */
    public function verifyHash(WorkflowEntryRecorder $recorder, string $tempFile, array $pharAsset, bool $quiet = false): bool
    {
        // Extract digest from the asset's JSON object (format: "sha256:...")
        $digest = $pharAsset['digest'] ?? null;

        // Calculate the local file's SHA-256 hash
        $calculatedHash = @hash_file('sha256', $tempFile);
        if ($calculatedHash === false) {
            $recorder->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.error_hash_calculation'));

            return false;
        }

        // Case A: Match (Verified) - proceed automatically
        $expectedHash = is_string($digest) && str_starts_with($digest, 'sha256:') ? substr($digest, 7) : $digest;
        if (is_string($digest) && is_string($expectedHash) && strtolower($calculatedHash) === strtolower($expectedHash)) {
            $recorder->addText(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.success_hash_verified'));

            return true;
        }

        // Case B: Mismatch or Missing Digest (Failed) - in quiet mode abort without prompting
        $errorMessage = $digest === null
            ? MessageRef::key('update.error_digest_not_found')
            : MessageRef::key('update.error_hash_mismatch', [
                'expected' => $digest,
                'calculated' => $calculatedHash,
            ]);

        $recorder->addWarning(WorkflowEntryRecorder::VERBOSITY_NORMAL, $errorMessage);

        // Otherwise the user decides: abort (false) or override and proceed to replacement (true)
        return ! $quiet && $this->prompt->confirm(
            MessageRef::key('update.prompt_continue_on_verification_failure'),
            false
        );
    }
}
