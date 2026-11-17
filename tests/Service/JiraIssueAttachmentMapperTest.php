<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\IssueAttachment;
use App\Service\JiraIssueAttachmentMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JiraIssueAttachmentMapperTest extends TestCase
{
    private JiraIssueAttachmentMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new JiraIssueAttachmentMapper();
    }

    public function testMapIssueAttachmentsReturnsEmptyWhenAttachmentFieldIsNotAList(): void
    {
        $this->assertSame([], $this->mapper->mapIssueAttachments([]));
        $this->assertSame([], $this->mapper->mapIssueAttachments(['attachment' => 'nope']));
    }

    public function testMapIssueAttachmentsSkipsNonArrayAndInvalidRows(): void
    {
        $attachments = $this->mapper->mapIssueAttachments([
            'attachment' => [
                'not-a-row',
                ['id' => '1', 'filename' => 'a.txt'],
                ['id' => 2, 'filename' => 'b.png', 'content' => 'https://jira/b.png', 'size' => 5, 'mimeType' => 'image/png'],
            ],
        ]);

        $this->assertEquals(
            [new IssueAttachment(id: '2', filename: 'b.png', size: 5, contentUrl: 'https://jira/b.png', mimeType: 'image/png')],
            $attachments,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('invalidRowProvider')]
    public function testNormalizeAttachmentRowReturnsNullForIncompleteRows(array $row): void
    {
        $this->assertNull($this->mapper->normalizeAttachmentRow($row));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidRowProvider(): iterable
    {
        $valid = ['id' => '1', 'filename' => 'a.txt', 'content' => 'https://jira/a.txt'];

        yield 'missing id' => [array_diff_key($valid, ['id' => true])];
        yield 'empty id' => [['id' => ''] + $valid];
        yield 'missing filename' => [array_diff_key($valid, ['filename' => true])];
        yield 'non-string filename' => [['filename' => 12] + $valid];
        yield 'empty filename' => [['filename' => ''] + $valid];
        yield 'missing content' => [array_diff_key($valid, ['content' => true])];
        yield 'non-string content' => [['content' => 12] + $valid];
        yield 'empty content' => [['content' => ''] + $valid];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('sizeAndMimeTypeProvider')]
    public function testNormalizeAttachmentRowNormalizesSizeAndMimeType(array $extra, int $expectedSize, ?string $expectedMimeType): void
    {
        $attachment = $this->mapper->normalizeAttachmentRow(
            $extra + ['id' => '7', 'filename' => 'a.txt', 'content' => 'https://jira/a.txt'],
        );

        $this->assertNotNull($attachment);
        $this->assertSame('7', $attachment->id);
        $this->assertSame('a.txt', $attachment->filename);
        $this->assertSame('https://jira/a.txt', $attachment->contentUrl);
        $this->assertSame($expectedSize, $attachment->size);
        $this->assertSame($expectedMimeType, $attachment->mimeType);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, ?string}>
     */
    public static function sizeAndMimeTypeProvider(): iterable
    {
        yield 'defaults' => [[], 0, null];
        yield 'int size and mime type' => [['size' => 42, 'mimeType' => 'text/plain'], 42, 'text/plain'];
        yield 'numeric string size' => [['size' => '128'], 128, null];
        yield 'non-numeric size' => [['size' => 'big'], 0, null];
        yield 'empty mime type' => [['mimeType' => ''], 0, null];
        yield 'non-string mime type' => [['mimeType' => 5], 0, null];
    }
}
