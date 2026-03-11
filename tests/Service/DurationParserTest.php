<?php

namespace App\Tests\Service;

use App\Service\DurationParser;
use PHPUnit\Framework\TestCase;

class DurationParserTest extends TestCase
{
    private DurationParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DurationParser();
    }

    public function testParseToSeconds_1d_returns86400(): void
    {
        $this->assertSame(86400, $this->parser->parseToSeconds('1d'));
        $this->assertSame(86400, $this->parser->parseToSeconds('1 day'));
        $this->assertSame(86400, $this->parser->parseToSeconds('1 days'));
    }

    public function testParseToSeconds_0_5d_returns43200(): void
    {
        $this->assertSame(43200, $this->parser->parseToSeconds('0.5d'));
    }

    public function testParseToSeconds_2h_returns7200(): void
    {
        $this->assertSame(7200, $this->parser->parseToSeconds('2h'));
        $this->assertSame(7200, $this->parser->parseToSeconds('2 hour'));
        $this->assertSame(7200, $this->parser->parseToSeconds('2 hours'));
    }

    public function testParseToSeconds_30m_returns1800(): void
    {
        $this->assertSame(1800, $this->parser->parseToSeconds('30m'));
        $this->assertSame(1800, $this->parser->parseToSeconds('30 minutes'));
        $this->assertSame(1800, $this->parser->parseToSeconds('30 min'));
        $this->assertSame(1800, $this->parser->parseToSeconds('30 minute'));
    }

    public function testParseToSeconds_invalid_returnsNull(): void
    {
        $this->assertNull($this->parser->parseToSeconds('invalid'));
        $this->assertNull($this->parser->parseToSeconds('1x'));
        $this->assertNull($this->parser->parseToSeconds(''));
    }

    public function testParseToSeconds_trimsWhitespace(): void
    {
        $this->assertSame(86400, $this->parser->parseToSeconds('  1d  '));
    }
}
