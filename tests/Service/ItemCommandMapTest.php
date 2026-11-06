<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommandMap;
use App\Service\ItemCommandMap;
use PHPUnit\Framework\TestCase;

class ItemCommandMapTest extends TestCase
{
    public function testCommandsListsWorkItemCommandsInHelpOrder(): void
    {
        $this->assertSame([
            'items:list',
            'items:search',
            'items:show',
            'items:download',
            'items:upload',
            'items:create',
            'items:update',
            'items:transition',
            'items:start',
            'items:takeover',
        ], array_keys(ItemCommandMap::commands()));
    }

    public function testCommandsAreExposedThroughCommandMap(): void
    {
        foreach (ItemCommandMap::commands() as $name => $meta) {
            $this->assertSame($meta, CommandMap::all()[$name]);
        }
    }

    public function testItemCreateCommandDocumentsAliasAndOptions(): void
    {
        $create = ItemCommandMap::commands()['items:create'];

        $this->assertSame('ic', $create['alias']);
        $this->assertSame('help.command_items_create', $create['description_key']);
        $this->assertSame(
            ['--project', '--type', '--summary', '--description', '--description-format', '--parent', '--fields'],
            array_column($create['options'], 'name'),
        );
        $this->assertSame([], $create['arguments']);
    }
}
