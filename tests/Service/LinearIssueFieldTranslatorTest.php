<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LinearApiClient;
use App\Service\LinearIssueFieldTranslator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinearIssueFieldTranslatorTest extends TestCase
{
    private LinearApiClient&MockObject $client;

    private LinearIssueFieldTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(LinearApiClient::class);
        $this->translator = new LinearIssueFieldTranslator();
    }

    public function testToCreateInputMapsHandlerFields(): void
    {
        $this->client->expects($this->once())->method('resolveTeamId')->with('ENG')->willReturn('team-1');
        $this->client->expects($this->exactly(2))
            ->method('resolveLabelIds')
            ->willReturnOnConsecutiveCalls(['label-bug'], []);
        $this->client->expects($this->once())->method('resolveIssueId')->with('ENG-9')->willReturn('parent-1');

        $input = $this->translator->toCreateInput([
            'project' => ['key' => 'ENG'],
            'summary' => 'Title',
            'description' => 'Body',
            'labels' => ['bug'],
            'priority' => ['name' => 'Urgent'],
            'parent' => ['key' => 'ENG-9'],
            'issuetype' => ['name' => 'Story'],
        ], $this->client, 'type-group');

        $this->assertSame('team-1', $input['teamId']);
        $this->assertSame('Title', $input['title']);
        $this->assertSame('Body', $input['description']);
        $this->assertSame(['label-bug'], $input['labelIds']);
        $this->assertSame(1, $input['priority']);
        $this->assertSame('parent-1', $input['parentId']);
    }

    public function testFormatDescriptionPayloadUsesLinearMarkdownMarker(): void
    {
        $payload = $this->translator->formatDescriptionPayload('hello');

        $this->assertSame('linearMarkdown', $payload['content'][0]['type']);
        $this->assertSame('hello', $payload['content'][0]['markdown']);
    }

    public function testExtractDescriptionFromAdfPlainText(): void
    {
        $this->client->expects($this->once())->method('resolveTeamId')->with('ENG')->willReturn('team-1');
        $this->client->expects($this->once())
            ->method('resolveLabelIds')
            ->willReturn([]);

        $payload = [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'line one']],
                ],
            ],
        ];

        $input = $this->translator->toCreateInput([
            'project' => ['key' => 'ENG'],
            'summary' => 'Title',
            'description' => $payload,
        ], $this->client);

        $this->assertSame('line one', $input['description']);
    }

    public function testLinearFieldMetaReturnsLabelsAndPriority(): void
    {
        $meta = $this->translator->linearFieldMeta();

        $this->assertArrayHasKey('labels', $meta);
        $this->assertArrayHasKey('priority', $meta);
    }

    public function testToUpdateInputMapsSummaryDescriptionLabelsAndPriority(): void
    {
        $this->client->expects($this->once())->method('resolveTeamKeyFromIssue')->with('SCI-1')->willReturn('SCI');
        $this->client->expects($this->once())->method('resolveLabelIds')->with('SCI', ['bug'], 'group-1')->willReturn(['label-bug']);

        $input = $this->translator->toUpdateInput([
            'summary' => 'Updated title',
            'description' => 'Plain body',
            'labels' => 'bug',
            'priority' => 'High',
        ], $this->client, 'SCI-1', 'group-1');

        $this->assertSame('Updated title', $input['title']);
        $this->assertSame('Plain body', $input['description']);
        $this->assertSame(['label-bug'], $input['labelIds']);
        $this->assertSame(2, $input['priority']);
    }

    public function testToCreateInputThrowsWhenProjectKeyMissing(): void
    {
        $this->expectException(\App\Exception\ApiException::class);

        $this->translator->toCreateInput(['summary' => 'Title'], $this->client);
    }

    public function testToCreateInputExtractsLinearMarkdownFromAdf(): void
    {
        $this->client->expects($this->once())->method('resolveTeamId')->willReturn('team-1');
        $this->client->expects($this->once())->method('resolveLabelIds')->willReturn([]);

        $input = $this->translator->toCreateInput([
            'project' => ['key' => 'SCI'],
            'summary' => 'Title',
            'description' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [['type' => 'linearMarkdown', 'markdown' => '## Spec']],
            ],
        ], $this->client);

        $this->assertSame('## Spec', $input['description']);
    }

    public function testToCreateInputResolvesStringPriorityAndSkipsZeroPriority(): void
    {
        $this->client->expects($this->exactly(2))->method('resolveTeamId')->willReturn('team-1');
        $this->client->expects($this->exactly(2))->method('resolveLabelIds')->willReturn([]);

        $withString = $this->translator->toCreateInput([
            'project' => ['key' => 'SCI'],
            'summary' => 'A',
            'priority' => 'Low',
        ], $this->client);
        $this->assertSame(4, $withString['priority']);

        $withZero = $this->translator->toCreateInput([
            'project' => ['key' => 'SCI'],
            'summary' => 'B',
            'priority' => 0,
        ], $this->client);
        $this->assertNull($withZero['priority']);
    }

    public function testProtectedHelpersCoverEdgeBranches(): void
    {
        $this->assertSame([], $this->invoke('normalizeLabelNames', [123]));
        $this->assertSame(['solo'], $this->invoke('normalizeLabelNames', ['solo']));
        $this->assertNull($this->invoke('resolveIssueTypeName', [['issuetype' => 'not-array']]));
        $this->assertNull($this->invoke('resolveIssueTypeName', [['issuetype' => ['name' => '']]]));
        $this->assertNull($this->invoke('resolvePriorityValue', [['name' => '']]));
        $this->assertNull($this->invoke('resolvePriorityValue', [true]));
        $this->assertNull($this->invoke('resolveParentId', [['key' => ''], $this->client]));
        $this->assertNull($this->invoke('extractDescription', [42]));
        $this->assertNull($this->invoke('extractDescriptionFromAdf', [['content' => 'invalid']]));
        $this->assertNull($this->invoke('extractDescriptionFromAdf', [['content' => [['type' => 'paragraph']]]]));
        $this->assertSame('ok', $this->invoke('extractDescriptionFromAdf', [[
            'content' => ['not-array', ['type' => 'linearMarkdown', 'markdown' => 'ok']],
        ]]));
        $this->assertSame('nested', $this->invoke('flattenAdfText', [[
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'nested']]],
        ]]));
        $this->assertSame('', $this->invoke('flattenAdfText', [['not-array']]));
    }

    /**
     * @param list<mixed> $args
     */
    private function invoke(string $method, array $args): mixed
    {
        $reflection = new \ReflectionClass($this->translator);
        $callable = $reflection->getMethod($method);
        $callable->setAccessible(true);

        return $callable->invokeArgs($this->translator, $args);
    }
}
