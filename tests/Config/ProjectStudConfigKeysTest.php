<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\ProjectStudConfigFieldMap;
use App\Config\ProjectStudConfigKeys;
use PHPUnit\Framework\TestCase;

class ProjectStudConfigKeysTest extends TestCase
{
    public function testYamlKeysMatchFieldMapValues(): void
    {
        $yamlKeys = ProjectStudConfigKeys::yamlKeys();
        $mappedValues = array_values(ProjectStudConfigFieldMap::INPUT_TO_YAML);

        foreach ($mappedValues as $yamlKey) {
            $this->assertContains($yamlKey, $yamlKeys);
        }

        $this->assertContains(ProjectStudConfigKeys::MIGRATION_VERSION, $yamlKeys);
    }
}
