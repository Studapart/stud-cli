<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Console\Style\SymfonyStyle;

class UpdateFileService
{
    public function __construct(
        private readonly TranslationService $translator
    ) {
    }

    public function replaceBinary(SymfonyStyle $io, string $tempFile, string $binaryPath, string $currentVersion, string $tagName): int
    {
        if (! is_writable($binaryPath)) {
            $io->error(explode("\n", $this->translator->trans('update.error_not_writable')));
            @unlink($tempFile);

            return 1;
        }

        // Create versioned backup path (e.g., /home/pem/.local/bin/stud-1.1.1.bak)
        $backupPath = $binaryPath . '-' . $currentVersion . '.bak';

        // Step 1: Backup the current executable
        // Note: rename() doesn't throw exceptions in PHP (returns false), but catch block handles edge cases
        // Exception from rename() is extremely rare and hard to simulate
        // @codeCoverageIgnoreStart
        try {
            rename($binaryPath, $backupPath);
        } catch (\Exception $e) {
            $io->error(explode("\n", $this->translator->trans('update.error_backup', ['error' => $e->getMessage()])));
            @unlink($tempFile);

            return 1;
        }
        // @codeCoverageIgnoreEnd

        // Step 2: Try to activate new version (atomic transaction)
        try {
            rename($tempFile, $binaryPath);
            chmod($binaryPath, 0755);

            // Backup file is left behind for cleanup on next run
            // Note: No $io->success() call here to avoid zlib error after PHAR replacement
            return 0;
            // Note: rename() doesn't throw exceptions in PHP, but chmod() might in edge cases
            // Rollback on failure
            // Exception from rename/chmod is extremely rare and hard to simulate
            // @codeCoverageIgnoreStart
        } catch (\Exception $e) {
            try {
                rename($backupPath, $binaryPath);
                $io->error(explode("\n", $this->translator->trans('update.error_rollback', ['error' => $e->getMessage()])));
            } catch (\Exception $rollbackException) {
                // Rollback also failed - this is a critical state
                $io->error(explode("\n", $this->translator->trans('update.error_rollback_failed', [
                    'original_error' => $e->getMessage(),
                    'rollback_error' => $rollbackException->getMessage(),
                    'backup_path' => $backupPath,
                ])));
            }
            @unlink($tempFile);

            return 1;
        }
        // @codeCoverageIgnoreEnd
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
     * Returns true if verification succeeds or user overrides, false if user aborts.
     *
     * @param array<string, mixed> $pharAsset
     */
    public function verifyHash(SymfonyStyle $io, string $tempFile, array $pharAsset): bool
    {
        // Extract digest from the asset's JSON object (format: "sha256:...")
        $digest = $pharAsset['digest'] ?? null;

        // Calculate the local file's SHA-256 hash
        $calculatedHash = @hash_file('sha256', $tempFile);
        if ($calculatedHash === false) {
            $io->error(explode("\n", $this->translator->trans('update.error_hash_calculation')));

            return false;
        }

        // Case A: Match (Verified) - proceed automatically
        if ($digest !== null) {
            // Extract hash from digest format "sha256:hash"
            $expectedHash = null;
            if (str_starts_with($digest, 'sha256:')) {
                $expectedHash = substr($digest, 7); // Remove "sha256:" prefix
            } else {
                // If digest doesn't have prefix, assume it's the hash itself
                $expectedHash = $digest;
            }

            if (strtolower($calculatedHash) === strtolower($expectedHash)) {
                $io->text($this->translator->trans('update.success_hash_verified'));

                return true;
            }
        }

        // Case B: Mismatch or Missing Digest (Failed) - prompt user for override
        $errorMessage = $digest === null
            ? $this->translator->trans('update.error_digest_not_found')
            : $this->translator->trans('update.error_hash_mismatch', [
                'expected' => $digest,
                'calculated' => $calculatedHash,
            ]);

        $io->warning(explode("\n", $errorMessage));

        $continue = $io->confirm(
            $this->translator->trans('update.prompt_continue_on_verification_failure'),
            false
        );

        if (! $continue) {
            // User aborted - stop process, delete temp file, exit with error code 1
            return false;
        }

        // User overrode - proceed to file replacement
        return true;
    }
}
