<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProjectStudConfigAdequacyChecker;
use PHPUnit\Framework\TestCase;

class ProjectStudConfigAdequacyCheckerTest extends TestCase
{
    public function testIsAdequateWhenBaseBranchPresent(): void
    {
        $checker = new ProjectStudConfigAdequacyChecker();

        $this->assertTrue($checker->isAdequate(['baseBranch' => 'main']));
    }

    public function testIsInadequateWhenBaseBranchMissing(): void
    {
        $checker = new ProjectStudConfigAdequacyChecker();

        $this->assertFalse($checker->isAdequate([]));
        $this->assertFalse($checker->isAdequate(['migration_version' => 'x']));
        $this->assertFalse($checker->isAdequate(['projectKey' => 'SCI']));
    }

    public function testIsInadequateWhenBaseBranchEmpty(): void
    {
        $checker = new ProjectStudConfigAdequacyChecker();

        $this->assertFalse($checker->isAdequate(['baseBranch' => '']));
        $this->assertFalse($checker->isAdequate(['baseBranch' => '   ']));
    }
}
