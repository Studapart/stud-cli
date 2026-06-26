<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ProjectsLabelsHandler;
use App\Service\IssueTrackerResolver;
use App\Service\LinearMetadataClient;
use App\Tests\CommandTestCase;

class ProjectsLabelsHandlerTest extends CommandTestCase
{
    public function testHandleReturnsLinearLabelGroups(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', false)
            ->willReturn([
                [
                    'id' => 'group-1',
                    'name' => 'Type',
                    'labels' => [
                        ['id' => 'label-1', 'name' => 'Bug', 'color' => '#ff0000'],
                    ],
                ],
            ]);

        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            linearClient: $linearClient,
        );
        $response = $handler->handle('SCI', false);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->groups);
        $this->assertSame('group-1', $response->groups[0]['id']);
        $this->assertSame('Bug', $response->groups[0]['labels'][0]['name']);
    }

    public function testHandlePassesGroupsOnlyFlagToClient(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', true)
            ->willReturn([]);

        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            linearClient: $linearClient,
        );
        $response = $handler->handle('SCI', true);

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->groups);
        $this->assertCount(1, $response->getWarnings());
    }

    public function testHandleReturnsNoticeForJiraProvider(): void
    {
        $response = $this->createHandler()->handle('SCI', false);

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->groups);
        $this->assertCount(1, $response->getNotices());
        $this->assertSame(
            'project.labels.labels_not_supported_for_jira',
            $response->getNotices()[0]->message->key,
        );
    }

    public function testHandleReturnsErrorWhenProviderAmbiguous(): void
    {
        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['jira', 'linear']],
            projectConfig: [],
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenLinearClientMissing(): void
    {
        $handler = new ProjectsLabelsHandler(
            null,
            new IssueTrackerResolver(),
            ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            [],
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Linear is not configured.', $response->getError());
    }

    public function testHandleReturnsErrorWhenLabelFetchFails(): void
    {
        $linearClient = $this->createMock(LinearMetadataClient::class);
        $linearClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->willThrowException(new \RuntimeException('Linear unavailable'));

        $handler = $this->createHandler(
            globalConfig: ['WORK_ITEM_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            linearClient: $linearClient,
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Linear unavailable', $response->getError());
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function createHandler(
        array $globalConfig = ['WORK_ITEM_PROVIDERS' => ['jira']],
        array $projectConfig = [],
        ?LinearMetadataClient $linearClient = null,
    ): ProjectsLabelsHandler {
        return new ProjectsLabelsHandler(
            $linearClient,
            new IssueTrackerResolver(),
            $globalConfig,
            $projectConfig,
        );
    }
}
