<?php

declare(strict_types=1);

namespace App\Tests\Guard;

use App\Guard\Capability\GitRepositoryAware;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\CapabilitySet;
use PHPUnit\Framework\TestCase;

class CapabilitySetTest extends TestCase
{
    public function testFromListDeduplicatesCapabilities(): void
    {
        $set = CapabilitySet::fromList([
            JiraAware::class,
            JiraAware::class,
            GitRepositoryAware::class,
        ]);

        $this->assertSame([
            JiraAware::class,
            GitRepositoryAware::class,
        ], $set->all());
    }

    public function testIsEmptyAndHas(): void
    {
        $empty = CapabilitySet::fromList([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($empty->has(JiraAware::class));

        $set = CapabilitySet::fromList([JiraAware::class]);
        $this->assertFalse($set->isEmpty());
        $this->assertTrue($set->has(JiraAware::class));
    }
}
