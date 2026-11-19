<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Points $_SERVER['HOME'] at a test workspace and restores the previous value in tearDown.
 */
trait RestoresServerHome
{
    private ?string $previousServerHome = null;

    private function pointHomeAt(string $home): void
    {
        $this->previousServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $home;
    }

    private function restoreServerHome(): void
    {
        if ($this->previousServerHome === null) {
            unset($_SERVER['HOME']);

            return;
        }

        $_SERVER['HOME'] = $this->previousServerHome;
    }
}
