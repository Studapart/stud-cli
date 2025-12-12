<?php

namespace App\Tests\Service;

use App\Service\ThemeDetector;
use PHPUnit\Framework\TestCase;

class ThemeDetectorTest extends TestCase
{
    public function testDetectReturnsLightByDefault(): void
    {
        // Clear environment variables that might affect detection
        putenv('COLORFGBG');
        putenv('TERM_PROGRAM');

        $detector = new ThemeDetector();
        $theme = $detector->detect();

        $this->assertSame('light', $theme);
    }

    public function testDetectReturnsDarkForLightTerminalBackground(): void
    {
        putenv('COLORFGBG=0;15'); // Background value >= 8 indicates light terminal
        putenv('TERM_PROGRAM');

        $detector = new ThemeDetector();
        $theme = $detector->detect();

        $this->assertSame('dark', $theme);
    }

    public function testDetectReturnsLightForDarkTerminalBackground(): void
    {
        putenv('COLORFGBG=0;7'); // Background value < 8 indicates dark terminal
        putenv('TERM_PROGRAM');

        $detector = new ThemeDetector();
        $theme = $detector->detect();

        $this->assertSame('light', $theme);
    }

    public function testDetectReturnsDarkForLightTerminalProgram(): void
    {
        putenv('COLORFGBG');
        putenv('TERM_PROGRAM=iTerm2-light');

        $detector = new ThemeDetector();
        $theme = $detector->detect();

        $this->assertSame('dark', $theme);
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('COLORFGBG');
        putenv('TERM_PROGRAM');
    }
}
