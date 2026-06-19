<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\CapabilityDiscovery;
use App\Handler\CommitHandler;
use App\Handler\ConfigValidateHandler;
use App\Handler\ItemListHandler;
use PHPUnit\Framework\TestCase;

class CapabilityDiscoveryTest extends TestCase
{
    public function testFromClassDiscoversImplementedMarkers(): void
    {
        $capabilities = CapabilityDiscovery::fromClass(CommitHandler::class);

        $this->assertTrue($capabilities->has(GitRepositoryAware::class));
        $this->assertTrue($capabilities->has(WorkItemJiraAware::class));
    }

    public function testFromClassReturnsEmptyForHandlerWithoutMarkers(): void
    {
        $capabilities = CapabilityDiscovery::fromClass(ConfigValidateHandler::class);

        $this->assertTrue($capabilities->isEmpty());
    }

    public function testFromClassDiscoversSingleMarker(): void
    {
        $capabilities = CapabilityDiscovery::fromClass(ItemListHandler::class);

        $this->assertSame([WorkItemJiraAware::class], $capabilities->all());
    }
}
