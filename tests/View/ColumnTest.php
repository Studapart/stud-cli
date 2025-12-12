<?php

namespace App\Tests\View;

use App\View\Column;
use PHPUnit\Framework\TestCase;

class ColumnTest extends TestCase
{
    public function testColumnCreationAndPropertyAccess(): void
    {
        $column = new Column(
            property: 'key',
            translationKey: 'table.key',
            formatter: null,
            condition: null
        );

        $this->assertSame('key', $column->property);
        $this->assertSame('table.key', $column->translationKey);
        $this->assertNull($column->formatter);
        $this->assertNull($column->condition);
    }

    public function testColumnWithFormatter(): void
    {
        $formatter = fn ($value) => strtoupper((string) $value);
        $column = new Column(
            property: 'status',
            translationKey: 'table.status',
            formatter: $formatter
        );

        $this->assertSame($formatter, $column->formatter);
    }

    public function testColumnWithCondition(): void
    {
        $column = new Column(
            property: 'priority',
            translationKey: 'table.priority',
            condition: 'priority'
        );

        $this->assertSame('priority', $column->condition);
    }

    public function testFormatterCallableExecution(): void
    {
        $formatter = fn ($value) => 'Formatted: ' . (string) $value;
        $column = new Column(
            property: 'key',
            translationKey: 'table.key',
            formatter: $formatter
        );

        $result = ($column->formatter)('TPW-1', []);

        $this->assertSame('Formatted: TPW-1', $result);
    }
}
