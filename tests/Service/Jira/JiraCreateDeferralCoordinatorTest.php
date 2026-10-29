<?php

declare(strict_types=1);

namespace App\Tests\Service\Jira;

use App\Exception\ApiException;
use App\Service\DurationParser;
use App\Service\FieldsParser;
use App\Service\IssueTrackerPort;
use App\Service\Jira\JiraCreateDeferErrorParser;
use App\Service\Jira\JiraCreateDeferralCoordinator;
use App\Service\Jira\JiraIssueFieldWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class JiraCreateDeferralCoordinatorTest extends TestCase
{
    private IssueTrackerPort&MockObject $port;

    private JiraCreateDeferralCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->port = $this->createMock(IssueTrackerPort::class);
        $this->coordinator = new JiraCreateDeferralCoordinator(
            new JiraCreateDeferErrorParser(),
            new JiraIssueFieldWriter(new FieldsParser(new DurationParser())),
        );
    }

    public function testFirstCreateSuccessSkipsRetry(): void
    {
        $this->port->method('create')->willReturn(['key' => 'SCI-0', 'self' => 'self']);

        $response = $this->coordinator->create($this->port, ['title' => 'Summary'], [], [], [], []);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->deferredFields);
    }

    public function testNonDeferrable400IsReturnedAsCreateError(): void
    {
        $this->port->method('create')->willThrowException(new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"summary":"is required"}}',
            400,
        ));

        $response = $this->coordinator->create($this->port, ['title' => 'Summary'], [], [], [], []);

        $this->assertFalse($response->isSuccess());
    }

    public function testRenderedCreateErrorKeepsABoundedBody(): void
    {
        $this->port->method('create')->willThrowException(new ApiException(
            'Could not create issue.',
            'HTTP 400: ' . str_repeat('A', 2000),
            400,
        ));

        $response = $this->coordinator->create($this->port, ['title' => 'Summary'], [], [], [], []);

        $error = $response->getErrorMessage();
        $this->assertInstanceOf(\App\DTO\MessageRef::class, $error);
        $this->assertSame('item.create.error_create', $error->key);
        $rendered = (string) $error->parameters['error'];
        $this->assertLessThan(600, mb_strlen($rendered));
        $this->assertStringEndsWith('... (truncated)', $rendered);
    }

    public function testNonApiCreateFailureIsReturned(): void
    {
        $this->port->method('create')->willThrowException(new \RuntimeException('socket down'));

        $response = $this->coordinator->create($this->port, ['title' => 'Summary'], [], [], [], []);

        $error = $response->getErrorMessage();
        $this->assertInstanceOf(\App\DTO\MessageRef::class, $error);
        $this->assertStringContainsString('socket down', (string) $error->parameters['error']);
    }

    public function testJiraSummaryKeyIsMappedWhenTitleIsAbsent(): void
    {
        $screenError = new ApiException('Could not create issue.', 'HTTP 400: {"errors":{"summary":"cannot be set"}}', 400);
        $this->port->expects($this->once())->method('create')->willThrowException($screenError);

        $response = $this->coordinator->create($this->port, ['summary' => 'Summary'], [], [], [], []);

        $this->assertFalse($response->isSuccess());
    }

    public function testRetriesCreateThenUpdatesDeferredLabels(): void
    {
        $screenError = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"labels":"Field \'labels\' cannot be set. It is not on the appropriate screen."}}',
            400,
        );
        $payloads = [];
        $this->recordCreates(
            $payloads,
            $screenError,
            ['key' => 'SCI-1', 'self' => 'https://example.test/SCI-1'],
        );
        $this->port->method('getEditMetaFields')->willReturn([
            'labels' => ['required' => false, 'name' => 'Labels'],
        ]);
        $this->port->expects($this->once())
            ->method('update')
            ->with('SCI-1', ['labels' => ['AI-Generated']]);

        $response = $this->coordinator->create(
            $this->port,
            ['title' => 'Summary', 'labels' => ['AI-Generated']],
            ['labels' => ['AI-Generated']],
            ['labels' => 'labels'],
            ['labels' => ['required' => false, 'name' => 'Labels']],
            [],
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['labels'], $response->deferredFields);
        $this->assertNull($response->updateWarnings);
        $this->assertArrayHasKey('labels', $payloads[0]);
        $this->assertArrayNotHasKey('labels', $payloads[1]);
        $this->assertSame('Summary', $payloads[1]['title']);
    }

    public function testSecondCreateFailureIncludesBothErrors(): void
    {
        $first = new ApiException('Could not create issue.', 'HTTP 400: {"errors":{"labels":"cannot be set"}}', 400);
        $second = new ApiException('Could not create issue.', 'HTTP 400: still blocked', 400);
        $payloads = [];
        $this->recordCreates($payloads, $first, $second);

        $response = $this->coordinator->create(
            $this->port,
            ['title' => 'Summary', 'labels' => ['AI-Generated']],
            ['labels' => ['AI-Generated']],
            ['labels' => 'labels'],
            [],
            [],
        );

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(\App\DTO\MessageRef::class, $error);
        $this->assertSame('item.create.error_create_retry', $error->key);
        $this->assertSame('labels', $error->parameters['fields']);
        $this->assertStringContainsString('still blocked', (string) $error->parameters['error']);
        $this->assertStringContainsString('cannot be set', (string) $error->parameters['error']);
        $this->assertArrayNotHasKey('labels', $payloads[1]);
        $this->assertSame('Summary', $payloads[1]['title']);
    }

    public function testUpdateFailureReturnsSuccessWithWarning(): void
    {
        $screenError = new ApiException('Could not create issue.', 'HTTP 400: {"errors":{"labels":"cannot be set"}}', 400);
        $this->port->method('create')->willReturnOnConsecutiveCalls(
            $this->throwException($screenError),
            ['key' => 'SCI-2', 'self' => 'self'],
        );
        $this->port->method('getEditMetaFields')->willReturn([
            'labels' => ['required' => false, 'name' => 'Labels'],
        ]);
        $this->port->method('update')->willThrowException(new ApiException('Could not update issue.', 'HTTP 400', 400));

        $response = $this->coordinator->create(
            $this->port,
            ['title' => 'Summary', 'labels' => ['AI-Generated']],
            ['labels' => ['AI-Generated']],
            ['labels' => 'labels'],
            [],
            [],
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['Labels'], $response->updateWarnings);
    }

    public function testRequiredCreateFieldIsNotDeferred(): void
    {
        $screenError = new ApiException('Could not create issue.', 'HTTP 400: {"errors":{"labels":"cannot be set"}}', 400);
        $this->port->expects($this->once())->method('create')->willThrowException($screenError);

        $response = $this->coordinator->create(
            $this->port,
            ['title' => 'Summary', 'labels' => ['AI-Generated']],
            ['labels' => ['AI-Generated']],
            ['labels' => 'labels'],
            ['labels' => ['required' => true, 'name' => 'Labels']],
            [],
        );

        $this->assertFalse($response->isSuccess());
        $error = $response->getErrorMessage();
        $this->assertInstanceOf(\App\DTO\MessageRef::class, $error);
        $this->assertSame('item.create.error_required_on_create_screen', $error->key);
    }

    public function testImmutableSummaryErrorIsNotRetried(): void
    {
        $screenError = new ApiException('Could not create issue.', 'HTTP 400: {"errors":{"summary":"cannot be set"}}', 400);
        $this->port->expects($this->once())->method('create')->willThrowException($screenError);

        $response = $this->coordinator->create(
            $this->port,
            ['title' => 'Summary'],
            [],
            [],
            [],
            [],
        );

        $this->assertFalse($response->isSuccess());
    }

    public function testUnmappedErrorDefersEveryFieldInput(): void
    {
        $screenError = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"customfield_12":"cannot be set"}}',
            400,
        );
        $payloads = [];
        $this->recordCreates($payloads, $screenError, ['key' => 'SCI-3', 'self' => 'self']);
        $this->port->method('getEditMetaFields')->willReturn([
            'labels' => ['required' => false, 'name' => 'Labels'],
        ]);
        $this->port->expects($this->once())
            ->method('update')
            ->with('SCI-3', ['labels' => ['AI-Generated']]);

        $response = $this->coordinator->create(
            $this->port,
            ['title' => 'Summary', 'customfield_12' => ['AI-Generated']],
            ['labels' => ['AI-Generated']],
            [],
            [],
            [],
        );

        $this->assertSame(['labels'], $response->deferredFields);
        $this->assertArrayHasKey('customfield_12', $payloads[0]);
        $this->assertArrayNotHasKey('customfield_12', $payloads[1]);
        $this->assertSame('Summary', $payloads[1]['title']);
    }

    public function testRetryRestoresAutofilledAssigneeOnUpdate(): void
    {
        $screenError = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"assignee":"cannot be set"}}',
            400,
        );
        $payloads = [];
        $this->recordCreates($payloads, $screenError, ['key' => 'SCI-4', 'self' => 'self']);
        $this->port->method('getEditMetaFields')->willReturn([
            'assignee' => ['required' => false, 'name' => 'Assignee'],
            'labels' => ['required' => false, 'name' => 'Labels'],
        ]);
        $updated = null;
        $this->port->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $key, array $fields) use (&$updated): void {
                $updated = [$key => $fields];
            });

        $response = $this->coordinator->create(
            $this->port,
            [
                'title' => 'Summary',
                'labels' => ['AI-Generated'],
                'assignee' => ['accountId' => 'abc'],
            ],
            ['labels' => ['AI-Generated']],
            ['labels' => 'labels'],
            [
                'labels' => ['required' => false, 'name' => 'Labels'],
                'assignee' => ['required' => false, 'name' => 'Assignee'],
            ],
            [],
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['SCI-4' => [
            'assignee' => ['accountId' => 'abc'],
        ]], $updated);
        $this->assertArrayNotHasKey('assignee', $payloads[1]);
        $this->assertSame(['AI-Generated'], $payloads[1]['labels']);
        $this->assertNull($response->updateWarnings);
        $this->assertContains('assignee', $response->deferredFields ?? []);
    }

    public function testRetryWarnsWhenAutofilledAssigneeIsNotEditable(): void
    {
        $screenError = new ApiException(
            'Could not create issue.',
            'HTTP 400: {"errors":{"assignee":"cannot be set"}}',
            400,
        );
        $payloads = [];
        $this->recordCreates($payloads, $screenError, ['key' => 'SCI-5', 'self' => 'self']);
        $this->port->method('getEditMetaFields')->willReturn([
            'labels' => ['required' => false, 'name' => 'Labels'],
        ]);
        $this->port->expects($this->never())->method('update');

        $response = $this->coordinator->create(
            $this->port,
            [
                'title' => 'Summary',
                'assignee' => ['accountId' => 'abc'],
            ],
            [],
            [],
            ['assignee' => ['required' => false, 'name' => 'Assignee']],
            [],
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['Assignee'], $response->updateWarnings);
        $this->assertSame(['assignee'], $response->deferredFields);
        $this->assertArrayNotHasKey('assignee', $payloads[1]);
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function recordCreates(array &$payloads, ApiException $first, array|ApiException $second): void
    {
        $this->port->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (array $fields) use (&$payloads, $first, $second): array {
                $payloads[] = $fields;
                if (count($payloads) === 1) {
                    throw $first;
                }
                if ($second instanceof ApiException) {
                    throw $second;
                }

                return $second;
            });
    }
}
