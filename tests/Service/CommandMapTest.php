<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommandMap;
use PHPUnit\Framework\TestCase;

class CommandMapTest extends TestCase
{
    public function testAliasLookupMapMatchesCommandMapAliases(): void
    {
        $lookup = CommandMap::aliasLookupMap();
        foreach (CommandMap::all() as $name => $meta) {
            $alias = $meta['alias'] ?? null;
            if (! is_string($alias) || $alias === '') {
                continue;
            }
            $this->assertArrayHasKey($alias, $lookup, "Missing alias '{$alias}' for command '{$name}'");
            $this->assertSame($name, $lookup[$alias], "Alias '{$alias}' must map to '{$name}'");
        }
    }

    public function testAliasLookupMapIncludesPreviouslyMissingHelpAliases(): void
    {
        $lookup = CommandMap::aliasLookupMap();
        $this->assertSame('items:download', $lookup['idl']);
        $this->assertSame('items:update', $lookup['iu']);
        $this->assertSame('branches:list', $lookup['bl']);
        $this->assertSame('branches:clean', $lookup['bc']);
        $this->assertSame('flatten', $lookup['ft']);
        $this->assertSame('sync', $lookup['sy']);
        $this->assertSame('cache:clear', $lookup['cc']);
        $this->assertSame('update', $lookup['up']);
    }

    public function testAliasLookupMapHasNoDuplicateAliases(): void
    {
        $lookup = CommandMap::aliasLookupMap();
        $aliasesFromMeta = [];
        foreach (CommandMap::all() as $name => $meta) {
            $alias = $meta['alias'] ?? null;
            if (is_string($alias) && $alias !== '') {
                $aliasesFromMeta[] = $alias;
            }
        }
        $this->assertSame(count($aliasesFromMeta), count($lookup), 'Each alias must map to exactly one command');
    }
}
