<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\GitRemoteUrlParser;
use App\Service\ProcessFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitRemoteUrlParserTest extends TestCase
{
    private ProcessFactory&MockObject $processFactory;
    private GitRemoteUrlParser $parser;

    protected function setUp(): void
    {
        $this->processFactory = $this->createMock(ProcessFactory::class);
        $this->parser = new GitRemoteUrlParser($this->processFactory);
    }

    /**
     * @dataProvider urlProvider
     *
     * @param array{owner?: string, name?: string, provider?: string} $expected
     */
    public function testParseUrl(string $url, array $expected): void
    {
        $this->assertSame($expected, $this->parser->parseUrl($url));
    }

    /**
     * @return iterable<string, array{string, array{owner?: string, name?: string, provider?: string}}>
     */
    public static function urlProvider(): iterable
    {
        yield 'github ssh' => ['git@github.com:studapart/stud-cli.git', ['owner' => 'studapart', 'name' => 'stud-cli', 'provider' => 'github']];
        yield 'github https' => ['https://github.com/studapart/stud-cli.git', ['owner' => 'studapart', 'name' => 'stud-cli', 'provider' => 'github']];
        yield 'gitlab nested' => ['git@gitlab.com:group/subgroup/repo.git', ['owner' => 'group/subgroup', 'name' => 'repo', 'provider' => 'gitlab']];
        yield 'custom gitlab host' => ['https://git.example.com/acme/project.git', ['owner' => 'acme', 'name' => 'project', 'provider' => 'gitlab']];
        yield 'unparseable' => ['not-a-url', []];
    }

    public function testParseRemoteUsesGitConfig(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())->method('run');
        $process->expects($this->once())->method('isSuccessful')->willReturn(true);
        $process->expects($this->once())->method('getOutput')->willReturn('git@github.com:owner/repo.git');

        $this->assertSame(
            ['owner' => 'owner', 'name' => 'repo', 'provider' => 'github'],
            $this->parser->parseRemote('origin')
        );
    }

    public function testGetRemoteUrlReturnsNullWhenConfigMissing(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->with('git config --get remote.origin.url')
            ->willReturn($process);

        $process->expects($this->once())->method('run');
        $process->expects($this->once())->method('isSuccessful')->willReturn(false);

        $this->assertNull($this->parser->getRemoteUrl('origin'));
    }

    public function testGetRemoteUrlTrimsTrailingDot(): void
    {
        $process = $this->createMock(Process::class);
        $this->processFactory->expects($this->once())
            ->method('create')
            ->willReturn($process);

        $process->expects($this->once())->method('run');
        $process->expects($this->once())->method('isSuccessful')->willReturn(true);
        $process->expects($this->once())->method('getOutput')->willReturn('https://github.com/o/r.git.');

        $this->assertSame('https://github.com/o/r.git', $this->parser->getRemoteUrl());
    }
}
