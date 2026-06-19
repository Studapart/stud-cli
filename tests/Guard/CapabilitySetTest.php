<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\WorkItemJiraAware;
use App\Guard\CapabilitySet;
use PHPUnit\Framework\TestCase;

class CapabilitySetTest extends TestCase
{
    public function testFromListDeduplicatesCapabilities(): void
    {
        $set = CapabilitySet::fromList([
            WorkItemJiraAware::class,
            WorkItemJiraAware::class,
            GitRepositoryAware::class,
        ]);

        $this->assertSame([
            WorkItemJiraAware::class,
            GitRepositoryAware::class,
        ], $set->all());
    }

    public function testIsEmptyAndHas(): void
    {
        $empty = CapabilitySet::fromList([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->has(WorkItemJiraAware::class));

        $set = CapabilitySet::fromList([WorkItemJiraAware::class]);
        $this->assertFalse($set->isEmpty());
        $this->assertTrue($set->has(WorkItemJiraAware::class));
    }
}
