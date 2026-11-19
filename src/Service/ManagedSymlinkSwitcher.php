<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Points a managed symlink at a new target with a temporary link and one rename, so the old path is never missing.
 */
class ManagedSymlinkSwitcher
{
    public function switch(string $symlinkPath, string $newTarget): bool
    {
        $temporaryLink = $symlinkPath . '.new';
        @unlink($temporaryLink);
        if (! @symlink($newTarget, $temporaryLink)) {
            return false;
        }

        if (@rename($temporaryLink, $symlinkPath)) {
            return true;
        }

        @unlink($temporaryLink);

        return false;
    }
}
