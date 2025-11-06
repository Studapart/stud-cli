<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class FileSystemTest extends TestCase
{
    private FileSystem $fileSystem;
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileSystem = new FileSystem();
        $this->testDir = sys_get_temp_dir() . '/filesystem_test_' . uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (file_exists($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    private function removeDirectory(string $path): void
    {
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($path);
    }

    public function testFileExists(): void
    {
        $testFile = $this->testDir . '/test.txt';
        file_put_contents($testFile, 'content');
        $this->assertTrue($this->fileSystem->fileExists($testFile));
        $this->assertFalse($this->fileSystem->fileExists($this->testDir . '/nonexistent.txt'));
    }

    public function testParseFile(): void
    {
        $testYamlFile = $this->testDir . '/test.yaml';
        $data = ['key' => 'value'];
        file_put_contents($testYamlFile, Yaml::dump($data));
        $this->assertSame($data, $this->fileSystem->parseFile($testYamlFile));
    }

    public function testDumpFile(): void
    {
        $testYamlFile = $this->testDir . '/dump.yaml';
        $data = ['foo' => 'bar'];
        $this->fileSystem->dumpFile($testYamlFile, $data);
        $this->assertFileExists($testYamlFile);
        $this->assertSame($data, Yaml::parseFile($testYamlFile));
    }

    public function testIsDir(): void
    {
        $this->assertTrue($this->fileSystem->isDir($this->testDir));
        $this->assertFalse($this->fileSystem->isDir($this->testDir . '/nonexistent'));
    }

    public function testMkdir(): void
    {
        $newDir = $this->testDir . '/new_dir';
        $this->fileSystem->mkdir($newDir);
        $this->assertDirectoryExists($newDir);
    }

    public function testFilePutContents(): void
    {
        $testFile = $this->testDir . '/put_contents.txt';
        $content = 'Hello World';
        $this->fileSystem->filePutContents($testFile, $content);
        $this->assertFileExists($testFile);
        $this->assertSame($content, file_get_contents($testFile));
    }

    public function testDirname(): void
    {
        $path = '/a/b/c.txt';
        $this->assertSame('/a/b', $this->fileSystem->dirname($path));
        $this->assertSame('/a', $this->fileSystem->dirname('/a/b'));
        $this->assertSame('/', $this->fileSystem->dirname('/a'));
    }
}
