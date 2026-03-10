<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\AgentModeException;
use App\Service\AgentModeHelper;
use App\Service\AgentModeIoInterface;
use PHPUnit\Framework\TestCase;

class AgentModeHelperTest extends TestCase
{
    private AgentModeHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helper = new AgentModeHelper();
    }

    public function testReadAgentInputFromFileReturnsDecodedArray(): void
    {
        $tmp = $this->createTempJsonFile('{"key":"k","quiet":true}');
        $result = $this->helper->readAgentInput($tmp);
        $this->assertSame(['key' => 'k', 'quiet' => true], $result);
        @unlink($tmp);
    }

    public function testReadAgentInputFromFileWithEmptyObject(): void
    {
        $tmp = $this->createTempJsonFile('{}');
        $result = $this->helper->readAgentInput($tmp);
        $this->assertSame([], $result);
        @unlink($tmp);
    }

    public function testReadAgentInputThrowsOnInvalidJson(): void
    {
        $tmp = $this->createTempJsonFile('not json');
        $this->expectException(AgentModeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        $this->helper->readAgentInput($tmp);
        @unlink($tmp);
    }

    public function testReadAgentInputThrowsOnNonObjectJson(): void
    {
        $tmp = $this->createTempJsonFile('["array"]');
        $this->expectException(AgentModeException::class);
        $this->expectExceptionMessage('JSON input must be an object');
        $this->helper->readAgentInput($tmp);
        @unlink($tmp);
    }

    public function testReadAgentInputThrowsOnNullJson(): void
    {
        $tmp = $this->createTempJsonFile('null');
        $this->expectException(AgentModeException::class);
        $this->expectExceptionMessage('JSON input must be an object');
        $this->helper->readAgentInput($tmp);
        @unlink($tmp);
    }

    public function testReadAgentInputThrowsOnNonReadableFile(): void
    {
        $this->expectException(AgentModeException::class);
        $this->expectExceptionMessage('Cannot read input file');
        $this->helper->readAgentInput('/nonexistent/path/' . bin2hex(random_bytes(8)) . '.json');
    }

    public function testReadAgentInputThrowsWhenFileReaderReturnsFalse(): void
    {
        $tmp = $this->createTempJsonFile('{}');
        $helper = new AgentModeHelper(null, null, fn (string $path): false => false);
        $this->expectException(AgentModeException::class);
        $this->expectExceptionMessage('Failed to read input file');
        $helper->readAgentInput($tmp);
        @unlink($tmp);
    }

    public function testBuildSuccessPayload(): void
    {
        $data = ['projects' => [['key' => 'P', 'name' => 'Project']]];
        $payload = $this->helper->buildSuccessPayload($data);
        $this->assertTrue($payload['success']);
        $this->assertSame($data, $payload['data']);
    }

    public function testBuildErrorPayload(): void
    {
        $payload = $this->helper->buildErrorPayload('Something failed');
        $this->assertFalse($payload['success']);
        $this->assertSame('Something failed', $payload['error']);
    }

    public function testWriteAgentOutputProducesValidJson(): void
    {
        $payload = $this->helper->buildSuccessPayload(['foo' => 'bar']);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->assertSame('{"success":true,"data":{"foo":"bar"}}', $json);
    }

    public function testReadAgentInputFromInjectedIoReturnsDecodedArray(): void
    {
        $io = new class () implements AgentModeIoInterface {
            public function getContents(): string
            {
                return '{"key":"from-io"}';
            }

            public function write(string $data): void
            {
            }
        };
        $helper = new AgentModeHelper($io);
        $result = $helper->readAgentInput(null);
        $this->assertSame(['key' => 'from-io'], $result);
    }

    public function testReadAgentInputFromInjectedIoWithEmptyContentThrowsInvalidJson(): void
    {
        $io = new class () implements AgentModeIoInterface {
            public function getContents(): string
            {
                return '';
            }

            public function write(string $data): void
            {
            }
        };
        $helper = new AgentModeHelper($io);
        $this->expectException(\App\Exception\AgentModeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        $helper->readAgentInput(null);
    }

    public function testReadAgentInputFromStdinReaderWhenNoFileAndNoIo(): void
    {
        $helper = new AgentModeHelper(null, fn (): string => '{"from":"stdin-reader"}');
        $result = $helper->readAgentInput(null);
        $this->assertSame(['from' => 'stdin-reader'], $result);
    }

    public function testReadAgentInputWhenStdinIsTtyReturnsEmptyObject(): void
    {
        $helper = new AgentModeHelper(null, null, null, fn (): bool => true);
        $result = $helper->readAgentInput(null);
        $this->assertSame([], $result);
    }

    public function testReadAgentInputThrowsWhenStdinReaderReturnsEmpty(): void
    {
        $helper = new AgentModeHelper(null, fn (): string => '');
        $this->expectException(AgentModeException::class);
        $this->expectExceptionMessage('Failed to read stdin');
        $helper->readAgentInput(null);
    }

    public function testWriteAgentOutputToInjectedIoReturnsNullAndWritesToIo(): void
    {
        $io = new class () implements AgentModeIoInterface {
            public string $written = '';

            public function getContents(): string
            {
                return '';
            }

            public function write(string $data): void
            {
                $this->written .= $data;
            }
        };
        $helper = new AgentModeHelper($io);
        $result = $helper->writeAgentOutput($helper->buildSuccessPayload(['x' => 1]));
        $this->assertNull($result);
        $this->assertSame('{"success":true,"data":{"x":1}}' . "\n", $io->written);
    }

    public function testWriteAgentOutputWithNoIoReturnsJsonLine(): void
    {
        $helper = new AgentModeHelper();
        $result = $helper->writeAgentOutput($helper->buildSuccessPayload(['a' => 'b']));
        $this->assertSame('{"success":true,"data":{"a":"b"}}' . "\n", $result);
    }

    public function testExitCodeForPayload(): void
    {
        $helper = new AgentModeHelper();
        $this->assertSame(0, $helper->exitCodeForPayload($helper->buildSuccessPayload([])));
        $this->assertSame(1, $helper->exitCodeForPayload($helper->buildErrorPayload('err')));
    }

    private function createTempJsonFile(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stud_agent_');
        if ($tmp === false) {
            $this->fail('Could not create temp file');
        }
        file_put_contents($tmp, $content);

        return $tmp;
    }
}
