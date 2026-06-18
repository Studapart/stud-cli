<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\GlobalStudConfigFieldMap;
use PHPUnit\Framework\TestCase;

class GlobalStudConfigFieldMapTest extends TestCase
{
    public function testAllowedInputKeysMatchesInputToYamlKeys(): void
    {
        $this->assertSame(array_keys(GlobalStudConfigFieldMap::INPUT_TO_YAML), GlobalStudConfigFieldMap::allowedInputKeys());
    }
}
