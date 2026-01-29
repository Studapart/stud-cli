<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class FileSystemTest extends TestCase
{
    private FileSystem $fileSystem;
    private FilesystemOperator $flysystem;

    protected function setUp(): void
    {
        parent::setUp();
        $adapter = new InMemoryFilesystemAdapter();
        $this->flysystem = new FlysystemFilesystem($adapter);
        $this->fileSystem = new FileSystem($this->flysystem);
    }

    public function testFileExists(): void
    {
        $testFile = 'test.txt';
        $this->flysystem->write($testFile, 'content');
        $this->assertTrue($this->fileSystem->fileExists($testFile));
        $this->assertFalse($this->fileSystem->fileExists('nonexistent.txt'));
    }

    public function testParseFile(): void
    {
        $testYamlFile = 'test.yaml';
        $data = ['key' => 'value'];
        $this->flysystem->write($testYamlFile, Yaml::dump($data));
        $this->assertSame($data, $this->fileSystem->parseFile($testYamlFile));
    }

    public function testParseFileThrowsWhenFileDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read file: nonexistent.yaml');
        $this->fileSystem->parseFile('nonexistent.yaml');
    }

    public function testDumpFile(): void
    {
        $testYamlFile = 'dump.yaml';
        $data = ['foo' => 'bar'];
        $this->fileSystem->dumpFile($testYamlFile, $data);
        $this->assertTrue($this->flysystem->fileExists($testYamlFile));
        $content = $this->flysystem->read($testYamlFile);
        $this->assertSame($data, Yaml::parse($content));
    }

    public function testIsDir(): void
    {
        $this->flysystem->createDirectory('test_dir');
        $this->assertTrue($this->fileSystem->isDir('test_dir'));
        $this->assertFalse($this->fileSystem->isDir('nonexistent'));
    }

    public function testMkdir(): void
    {
        $newDir = 'new_dir';
        $result = $this->fileSystem->mkdir($newDir);
        $this->assertTrue($result);
        $this->assertTrue($this->flysystem->directoryExists($newDir));
    }

    public function testFilePutContents(): void
    {
        $testFile = 'put_contents.txt';
        $content = 'Hello World';
        $this->fileSystem->filePutContents($testFile, $content);
        $this->assertTrue($this->flysystem->fileExists($testFile));
        $this->assertSame($content, $this->flysystem->read($testFile));
    }

    public function testDirname(): void
    {
        $path = '/a/b/c.txt';
        $this->assertSame('/a/b', $this->fileSystem->dirname($path));
        $this->assertSame('/a', $this->fileSystem->dirname('/a/b'));
        $this->assertSame('/', $this->fileSystem->dirname('/a'));
    }

    public function testCreateLocal(): void
    {
        $fileSystem = \App\Service\FileSystem::createLocal();
        $this->assertInstanceOf(\App\Service\FileSystem::class, $fileSystem);
    }

    public function testMkdirReturnsFalseOnException(): void
    {
        // Create a mock filesystem that throws an exception
        $mockFilesystem = $this->createMock(FilesystemOperator::class);
        $mockFilesystem->expects($this->once())
            ->method('createDirectory')
            ->willThrowException(new \League\Flysystem\UnableToCreateDirectory('test'));

        $fileSystem = new FileSystem($mockFilesystem);
        $result = $fileSystem->mkdir('test_dir');

        $this->assertFalse($result);
    }
}
