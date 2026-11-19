<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MessageRef;
use App\Service\FileSystem;
use App\Service\ItemUploadPathValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ItemUploadPathValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function traversalPathProvider(): iterable
    {
        yield 'leading parent' => ['../evil'];
        yield 'nested parent' => ['docs/../../evil'];
        yield 'windows separator' => ['docs\\..\\evil'];
    }

    #[DataProvider('traversalPathProvider')]
    public function testRejectsTraversalWithoutTouchingFileSystem(string $path): void
    {
        $fileSystem = $this->createMock(FileSystem::class);
        $fileSystem->expects($this->never())->method('fileExists');

        $rejection = (new ItemUploadPathValidator($fileSystem))->rejectionFor($path);

        $this->assertRejection($rejection, 'item.upload.error_path_traversal');
    }

    public function testRejectsMissingFile(): void
    {
        $fileSystem = $this->createStub(FileSystem::class);
        $fileSystem->method('fileExists')->willReturn(false);

        $rejection = (new ItemUploadPathValidator($fileSystem))->rejectionFor('missing.txt');

        $this->assertRejection($rejection, 'item.upload.error_not_found');
    }

    public function testRejectsDirectory(): void
    {
        $fileSystem = $this->createStub(FileSystem::class);
        $fileSystem->method('fileExists')->willReturn(true);
        $fileSystem->method('isDir')->willReturn(true);

        $rejection = (new ItemUploadPathValidator($fileSystem))->rejectionFor('docs');

        $this->assertRejection($rejection, 'item.upload.error_not_file');
    }

    public function testAcceptsExistingFileWithDotsInName(): void
    {
        $fileSystem = $this->createStub(FileSystem::class);
        $fileSystem->method('fileExists')->willReturn(true);
        $fileSystem->method('isDir')->willReturn(false);

        $this->assertNull((new ItemUploadPathValidator($fileSystem))->rejectionFor('docs/..notes/report..md'));
    }

    private function assertRejection(?MessageRef $rejection, string $expectedKey): void
    {
        $this->assertInstanceOf(MessageRef::class, $rejection);
        $this->assertSame($expectedKey, $rejection->key);
    }
}
