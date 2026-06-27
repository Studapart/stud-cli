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
}
