<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\OutputFormat;
use PHPUnit\Framework\TestCase;

class OutputFormatTest extends TestCase
{
    public function testCliValue(): void
    {
        $this->assertSame('cli', OutputFormat::Cli->value);
    }

    public function testJsonValue(): void
    {
        $this->assertSame('json', OutputFormat::Json->value);
    }

    public function testFromString(): void
    {
        $this->assertSame(OutputFormat::Cli, OutputFormat::from('cli'));
        $this->assertSame(OutputFormat::Json, OutputFormat::from('json'));
    }
}
