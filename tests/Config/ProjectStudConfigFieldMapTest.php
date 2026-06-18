<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ProjectStudConfigFieldMap;
use PHPUnit\Framework\TestCase;

class ProjectStudConfigFieldMapTest extends TestCase
{
    public function testAllowedInputKeysIncludesLinearAndWorkItemProviderFields(): void
    {
        $allowed = ProjectStudConfigFieldMap::allowedInputKeys();

        $this->assertContains('workItemProvider', $allowed);
        $this->assertContains('linearStartStateId', $allowed);
        $this->assertContains('linearTypeLabelGroupId', $allowed);
        $this->assertContains('linearTypeBranchPrefixes', $allowed);
        $this->assertSame(array_keys(ProjectStudConfigFieldMap::INPUT_TO_YAML), array_values(array_filter(
            $allowed,
            static fn (string $key): bool => isset(ProjectStudConfigFieldMap::INPUT_TO_YAML[$key]),
        )));
    }
}
