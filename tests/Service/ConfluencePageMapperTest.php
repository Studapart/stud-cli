<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ConfluencePageMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfluencePageMapperTest extends TestCase
{
    private ConfluencePageMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ConfluencePageMapper();
    }

    public function testMapPageReturnsIdTitleAndWebuiLink(): void
    {
        $result = $this->mapper->mapPage(['id' => 42, 'title' => 'Doc', '_links' => ['webui' => '/spaces/X/pages/42']]);

        self::assertSame(['id' => '42', 'title' => 'Doc', '_links' => ['webui' => '/spaces/X/pages/42']], $result);
    }

    public function testMapPageDefaultsMissingFields(): void
    {
        $result = $this->mapper->mapPage(['_links' => ['webui' => 7]]);

        self::assertSame(['id' => '', 'title' => '', '_links' => ['webui' => '']], $result);
    }

    public function testMapPageWithVersionUsesTopLevelSpaceId(): void
    {
        $result = $this->mapper->mapPageWithVersion([
            'id' => '1',
            'title' => 'T',
            'version' => ['number' => 3],
            'spaceId' => 99,
        ]);

        self::assertSame(
            ['id' => '1', 'title' => 'T', '_links' => ['webui' => ''], 'version' => ['number' => 3], 'spaceId' => '99'],
            $result,
        );
    }

    public function testMapPageWithVersionFallsBackToNestedSpaceId(): void
    {
        $result = $this->mapper->mapPageWithVersion(['id' => '1', 'space' => ['id' => 'S1']]);

        self::assertSame(0, $result['version']['number']);
        self::assertSame('S1', $result['spaceId']);
    }

    public function testMapPageWithVersionReturnsNullSpaceIdWhenAbsent(): void
    {
        self::assertNull($this->mapper->mapPageWithVersion(['id' => '1'])['spaceId']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array{type: string, content?: array<int, mixed>} $expected
     */
    #[DataProvider('bodyProvider')]
    public function testExtractBodyAdf(array $data, array $expected): void
    {
        self::assertSame($expected, $this->mapper->extractBodyAdf($data));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{type: string, content?: array<int, mixed>}}>
     */
    public static function bodyProvider(): iterable
    {
        $empty = ['type' => 'doc', 'content' => []];
        $paragraph = [['type' => 'paragraph']];

        yield 'no body' => [[], $empty];
        yield 'no atlas_doc_format' => [['body' => []], $empty];
        yield 'string value' => [
            ['body' => ['atlas_doc_format' => ['value' => json_encode(['type' => 'doc', 'content' => $paragraph])]]],
            ['type' => 'doc', 'content' => $paragraph],
        ];
        yield 'array value without content' => [['body' => ['atlas_doc_format' => ['value' => ['type' => 'doc']]]], $empty];
        yield 'invalid json value' => [['body' => ['atlas_doc_format' => ['value' => 'not json']]], $empty];
        yield 'missing value' => [['body' => ['atlas_doc_format' => []]], $empty];
    }
}
