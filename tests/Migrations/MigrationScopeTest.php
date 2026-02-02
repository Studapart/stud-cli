<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Migrations\MigrationScope;
use PHPUnit\Framework\TestCase;

class MigrationScopeTest extends TestCase
{
    public function testGlobalCase(): void
    {
        $scope = MigrationScope::GLOBAL;
        $this->assertSame('global', $scope->value);
        $this->assertInstanceOf(MigrationScope::class, $scope);
    }

    public function testProjectCase(): void
    {
        $scope = MigrationScope::PROJECT;
        $this->assertSame('project', $scope->value);
        $this->assertInstanceOf(MigrationScope::class, $scope);
    }

    public function testEnumCases(): void
    {
        // Test that both cases exist and have correct values
        $cases = MigrationScope::cases();
        $this->assertCount(2, $cases);
        $this->assertContains(MigrationScope::GLOBAL, $cases);
        $this->assertContains(MigrationScope::PROJECT, $cases);
    }
}
