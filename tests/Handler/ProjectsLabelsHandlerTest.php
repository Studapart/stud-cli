<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Handler\ProjectsLabelsHandler;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\LinearIssueTrackerAdapter;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ProjectsLabelsHandlerTest extends CommandTestCase
{
    public function testHandleReturnsLinearLabelGroups(): void
    {
        $port = $this->createLinearPortMock();
        $port->expects($this->once())
            ->method('listLabelGroups')
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
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: ['ok' => true, 'provider' => IssueTrackerProvider::Linear->value, 'port' => $port],
        );
        $response = $handler->handle('SCI', false);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->groups);
        $this->assertSame('group-1', $response->groups[0]['id']);
        $this->assertSame('Bug', $response->groups[0]['labels'][0]['name']);
    }

    public function testHandlePassesGroupsOnlyFlagToPort(): void
    {
        $port = $this->createLinearPortMock();
        $port->expects($this->once())
            ->method('listLabelGroups')
            ->with('SCI', true)
            ->willReturn([]);

        $handler = $this->createHandler(
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: ['ok' => true, 'provider' => IssueTrackerProvider::Linear->value, 'port' => $port],
        );
        $response = $handler->handle('SCI', true);

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->groups);
        $this->assertCount(1, $response->getWarnings());
    }

    public function testHandleReturnsNoticeWhenPortDoesNotSupportLabelGroups(): void
    {
        $port = $this->createMock(IssueTrackerPort::class);

        $response = $this->createHandler(
            resolveResult: ['ok' => true, 'provider' => IssueTrackerProvider::Jira->value, 'port' => $port],
        )->handle('SCI', false);

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
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value]],
            projectConfig: [],
            resolveResult: [
                'ok' => false,
                'error' => MessageRef::key('project.workflow.error_ambiguous_provider'),
            ],
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
    }

    public function testHandleReturnsErrorWhenLinearNotConfigured(): void
    {
        $handler = $this->createHandler(
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: [
                'ok' => false,
                'error' => IssueTrackerException::missingLinearApiKey()->messageRef,
            ],
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
        $this->assertSame(
            'issue_tracker_provider.missing_linear_api_key',
            (string) $response->getErrorMessage(),
        );
    }

    public function testHandleReturnsErrorWhenLabelFetchFailsWithApiException(): void
    {
        $port = $this->createLinearPortMock();
        $port->expects($this->once())
            ->method('listLabelGroups')
            ->willThrowException(new ApiException('Linear unavailable', 'HTTP 503'));

        $handler = $this->createHandler(
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: ['ok' => true, 'provider' => IssueTrackerProvider::Linear->value, 'port' => $port],
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('project.labels.error_fetch', $error->key);
    }

    public function testHandleReturnsErrorWhenLabelFetchFails(): void
    {
        $port = $this->createLinearPortMock();
        $port->expects($this->once())
            ->method('listLabelGroups')
            ->willThrowException(new \RuntimeException('Linear unavailable'));

        $handler = $this->createHandler(
            globalConfig: ['ISSUE_TRACKER_PROVIDERS' => ['linear'], 'LINEAR_API_KEY' => 'lin_api_test'],
            resolveResult: ['ok' => true, 'provider' => IssueTrackerProvider::Linear->value, 'port' => $port],
        );

        $response = $handler->handle('SCI', false);

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('project.labels.error_fetch', $error->key);
    }

    public function testHandleUsesProviderOverrideWhenGiven(): void
    {
        $port = $this->createLinearPortMock();
        $port->expects($this->once())->method('listLabelGroups')->willReturn([]);

        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->expects($this->never())->method('resolveForDiscovery');
        $supplier->expects($this->once())
            ->method('resolveForProvider')
            ->with(IssueTrackerProvider::Linear->value, $this->anything())
            ->willReturn(['ok' => true, 'provider' => IssueTrackerProvider::Linear->value, 'port' => $port]);

        $handler = new ProjectsLabelsHandler(
            $supplier,
            ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Linear->value], 'LINEAR_API_KEY' => 'lin'],
            [],
        );

        $response = $handler->handle('SCI', false, IssueTrackerProvider::Linear->value);

        $this->assertTrue($response->isSuccess());
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     * @param array<string, mixed> $resolveResult
     */
    private function createHandler(
        array $globalConfig = ['ISSUE_TRACKER_PROVIDERS' => [IssueTrackerProvider::Jira->value]],
        array $projectConfig = [],
        array $resolveResult = [],
    ): ProjectsLabelsHandler {
        $supplier = $this->createMock(IssueTrackerPortSupplier::class);
        $supplier->method('resolveForDiscovery')->willReturn($resolveResult);

        return new ProjectsLabelsHandler(
            $supplier,
            $globalConfig,
            $projectConfig,
        );
    }

    private function createLinearPortMock(): LinearIssueTrackerAdapter&MockObject
    {
        return $this->getMockBuilder(LinearIssueTrackerAdapter::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listLabelGroups'])
            ->getMock();
    }
}
