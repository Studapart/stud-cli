<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\ResponseMessageLevel;
use App\Service\DtoSerializer;
use PHPUnit\Framework\TestCase;

enum DtoSerializerTestUnitEnum
{
    case Example;
}

class DtoSerializerTest extends TestCase
{
    private DtoSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serializer = new DtoSerializer();
    }

    public function testSerializeWithScalarProperties(): void
    {
        $dto = new class () {
            public string $name = 'test';

            public int $count = 42;

            public bool $active = true;

            public ?string $nullable = null;
        };
        $result = $this->serializer->serialize($dto);
        $this->assertSame(['name' => 'test', 'count' => 42, 'active' => true, 'nullable' => null], $result);
    }

    public function testSerializeWithNestedObject(): void
    {
        $inner = new class () {
            public string $key = 'ABC-1';
        };
        $outer = new class ($inner) {
            public function __construct(public readonly object $child)
            {
            }

            public string $name = 'parent';
        };
        $result = $this->serializer->serialize($outer);
        $this->assertSame(['child' => ['key' => 'ABC-1'], 'name' => 'parent'], $result);
    }

    public function testSerializeWithDateTimeProperty(): void
    {
        $dto = new class () {
            public \DateTimeInterface $createdAt;

            public function __construct()
            {
                $this->createdAt = new \DateTimeImmutable('2025-01-15T10:30:00+00:00');
            }
        };
        $result = $this->serializer->serialize($dto);
        $this->assertSame('2025-01-15T10:30:00+00:00', $result['createdAt']);
    }

    public function testSerializeWithArrayOfObjects(): void
    {
        $item1 = new class () {
            public string $key = 'A';
        };
        $item2 = new class () {
            public string $key = 'B';
        };
        $dto = new class ([$item1, $item2]) {
            /** @param object[] $items */
            public function __construct(public readonly array $items)
            {
            }
        };
        $result = $this->serializer->serialize($dto);
        $this->assertSame([['key' => 'A'], ['key' => 'B']], $result['items']);
    }

    public function testSerializeList(): void
    {
        $dto1 = new class () {
            public string $id = '1';
        };
        $dto2 = new class () {
            public string $id = '2';
        };
        $result = $this->serializer->serializeList([$dto1, $dto2]);
        $this->assertSame([['id' => '1'], ['id' => '2']], $result);
    }

    public function testSerializeListEmpty(): void
    {
        $result = $this->serializer->serializeList([]);
        $this->assertSame([], $result);
    }

    public function testSerializeWithScalarArray(): void
    {
        $dto = new class () {
            /** @var string[] */
            public array $tags = ['php', 'cli'];
        };
        $result = $this->serializer->serialize($dto);
        $this->assertSame(['tags' => ['php', 'cli']], $result);
    }

    public function testSerializeWithNestedArrayOfObjects(): void
    {
        $child = new class () {
            public string $id = 'child';
        };
        $dto = new class ([['item' => $child]]) {
            /** @param array<int, array<string, object>> $groups */
            public function __construct(public readonly array $groups)
            {
            }
        };

        $result = $this->serializer->serialize($dto);

        $this->assertSame([['item' => ['id' => 'child']]], $result['groups']);
    }

    public function testSerializeWithBackedEnum(): void
    {
        $dto = new class () {
            public ResponseMessageLevel $level = ResponseMessageLevel::Warning;
        };

        $this->assertSame(['level' => 'warning'], $this->serializer->serialize($dto));
    }

    public function testSerializeWithUnitEnum(): void
    {
        $dto = new class () {
            public DtoSerializerTestUnitEnum $level = DtoSerializerTestUnitEnum::Example;
        };

        $this->assertSame(['level' => 'Example'], $this->serializer->serialize($dto));
    }
}
