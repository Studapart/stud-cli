<?php

namespace App\Tests\Service;

use App\Service\FileSystemPathPolicy;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;

class FileSystemPathPolicyTest extends TestCase
{
    public function testValidateAcceptsRegularPath(): void
    {
        $this->expectNotToPerformAssertions();

        $this->inMemoryPolicy()->validate('some/dir/file.txt');
    }

    public function testValidateRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains null byte');

        $this->inMemoryPolicy()->validate("file\0.txt");
    }

    public function testValidateRejectsControlCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path contains invalid control characters');

        $this->inMemoryPolicy()->validate("file\x01.txt");
    }

    public function testIsLocalFilesystemIsFalseForInMemoryAdapter(): void
    {
        $this->assertFalse($this->inMemoryPolicy()->isLocalFilesystem());
    }

    public function testIsLocalFilesystemIsFalseForNonFlysystemOperator(): void
    {
        $policy = new FileSystemPathPolicy($this->createStub(FilesystemOperator::class));

        $this->assertFalse($policy->isLocalFilesystem());
    }

    public function testIsLocalFilesystemIsTrueForLocalAdapter(): void
    {
        $this->assertTrue($this->localPolicy()->isLocalFilesystem());
    }

    public function testShouldUseNativeOperationsForTempPathOnInMemoryFilesystem(): void
    {
        $this->assertTrue($this->inMemoryPolicy()->shouldUseNativeOperations(sys_get_temp_dir() . '/stud-file.txt'));
        $this->assertTrue($this->inMemoryPolicy()->shouldUseNativeOperations('/tmp'));
    }

    public function testShouldNotUseNativeOperationsForRelativeOrNonTempPathOnInMemoryFilesystem(): void
    {
        $policy = $this->inMemoryPolicy();

        $this->assertFalse($policy->shouldUseNativeOperations('config.yml'));
        $this->assertFalse($policy->shouldUseNativeOperations(''));
        $this->assertFalse($policy->shouldUseNativeOperations('/home/user/.config/stud/config.yml'));
    }

    public function testShouldUseNativeOperationsForAbsolutePathOnLocalFilesystem(): void
    {
        $this->assertTrue($this->localPolicy()->shouldUseNativeOperations('/home/user/.config/stud/config.yml'));
    }

    public function testShouldUseNativeOperationsForTempPathOutsideLocalRoot(): void
    {
        $this->assertTrue($this->localPolicy()->shouldUseNativeOperations('/tmp/stud-outside-root.txt'));
    }

    public function testShouldNotUseNativeOperationsForRelativePathOnLocalFilesystem(): void
    {
        $this->assertFalse($this->localPolicy()->shouldUseNativeOperations('config.yml'));
    }

    public function testShouldNotUseNativeOperationsForTempPathWithinLocalRoot(): void
    {
        $originalCwd = getcwd() ?: '/';
        $tempRoot = sys_get_temp_dir() . '/stud-path-policy-' . uniqid();
        mkdir($tempRoot);
        chdir($tempRoot);

        try {
            $policy = new FileSystemPathPolicy(new FlysystemFilesystem(new LocalFilesystemAdapter($tempRoot)));

            $this->assertFalse($policy->shouldUseNativeOperations($tempRoot));
        } finally {
            chdir($originalCwd);
            rmdir($tempRoot);
        }
    }

    public function testResolveLocalFilePathPrefixesAdapterRoot(): void
    {
        $root = sys_get_temp_dir();
        $policy = new FileSystemPathPolicy(new FlysystemFilesystem(new LocalFilesystemAdapter($root)));

        $this->assertSame(rtrim($root, '/') . '/dir/file.txt', $policy->resolveLocalFilePath('/dir/file.txt'));
    }

    public function testIsPathWithinRootWithRelativePath(): void
    {
        $policy = $this->inMemoryPolicy();
        $method = (new \ReflectionClass($policy))->getMethod('isPathWithinRoot');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertTrue($method->invoke($policy, 'relative/path.txt'));
    }

    public function testIsPathWithinRootWithInMemoryFilesystem(): void
    {
        $policy = $this->inMemoryPolicy();
        $method = (new \ReflectionClass($policy))->getMethod('isPathWithinRoot');
        \App\Util\ReflectionAccessor::ensureAccessible($method);

        $this->assertTrue($method->invoke($policy, '/absolute/path.txt'));
    }

    private function inMemoryPolicy(): FileSystemPathPolicy
    {
        return new FileSystemPathPolicy(new FlysystemFilesystem(new InMemoryFilesystemAdapter()));
    }

    private function localPolicy(): FileSystemPathPolicy
    {
        return new FileSystemPathPolicy(new FlysystemFilesystem(new LocalFilesystemAdapter(getcwd() ?: '/')));
    }
}
