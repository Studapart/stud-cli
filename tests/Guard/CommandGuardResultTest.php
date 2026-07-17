<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\CommandGuardResult;
use PHPUnit\Framework\TestCase;

class CommandGuardResultTest extends TestCase
{
    public function testHasMissingKeysAndBlockingIssues(): void
    {
        $result = new CommandGuardResult(['JIRA_URL'], [], false);

        $this->assertTrue($result->hasMissingKeys());
        $this->assertTrue($result->hasBlockingIssues());
    }

    public function testEnvironmentFailureBlocksWithoutMissingKeys(): void
    {
        $result = new CommandGuardResult([], [], false, ['git_repository']);

        $this->assertFalse($result->hasMissingKeys());
        $this->assertTrue($result->hasBlockingIssues());
    }

    public function testCanProceedHasNoBlockingIssues(): void
    {
        $result = new CommandGuardResult([], [], true);

        $this->assertFalse($result->hasMissingKeys());
        $this->assertFalse($result->hasBlockingIssues());
    }
}
