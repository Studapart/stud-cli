<?php

namespace App\Tests\Handler;

use App\DTO\ItemUpdateInput;
use App\Exception\ApiException;
use App\Handler\ItemUpdateHandler;
use App\Service\DurationParser;
use App\Service\FieldsParser;
use App\Tests\CommandTestCase;

class ItemUpdateHandlerTest extends CommandTestCase
{
    private FieldsParser $fieldsParser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fieldsParser = new FieldsParser(new DurationParser());
    }

    private function createHandler(): ItemUpdateHandler
    {
        return new ItemUpdateHandler(
            $this->issueTracker,
            $this->translationService,
            $this->fieldsParser
        );
    }

    public function testUpdateSummarySuccess(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->with('SCI-71')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->with('SCI-71', $this->callback(fn ($f) => $f['summary'] === 'New title'));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'New title'));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('SCI-71', $response->key);
    }

    public function testUpdateDescriptionPlainSuccess(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->with('SCI-71')
            ->willReturn(['description' => ['required' => false, 'name' => 'Description']]);
        $this->issueTracker->expects($this->once())
            ->method('formatDescription')
            ->with('New desc', 'plain')
            ->willReturn(['type' => 'doc', 'content' => []]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->with('SCI-71', $this->callback(fn ($f) => isset($f['description'])));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', descriptionOption: 'New desc'));

        $this->assertTrue($response->isSuccess());
    }

    public function testUpdateDescriptionMarkdownSuccess(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['description' => ['required' => false, 'name' => 'Description']]);
        $this->issueTracker->expects($this->once())
            ->method('formatDescription')
            ->with('# Heading', 'markdown')
            ->willReturn(['type' => 'doc', 'content' => []]);
        $this->issueTracker->expects($this->once())->method('update');

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', descriptionOption: '# Heading', descriptionFormat: 'markdown'));

        $this->assertTrue($response->isSuccess());
    }

    public function testUpdateViaFieldsOption(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->with('SCI-71')
            ->willReturn([
                'labels' => ['required' => false, 'name' => 'Labels'],
                'priority' => ['required' => false, 'name' => 'Priority'],
            ]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->with('SCI-71', $this->callback(function ($f) {
                return $f['labels'] === ['AI-Generated', 'DX']
                    && $f['priority'] === ['name' => 'High'];
            }));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput(
            'SCI-71',
            fieldsOption: 'labels=AI-Generated,DX;priority=High'
        ));

        $this->assertTrue($response->isSuccess());
    }

    public function testUpdateViaFieldsMap(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['labels' => ['required' => false, 'name' => 'Labels']]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->with('SCI-71', $this->callback(fn ($f) => $f['labels'] === ['Bug']));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput(
            'SCI-71',
            fieldsMap: ['labels' => ['Bug']]
        ));

        $this->assertTrue($response->isSuccess());
    }

    public function testUnmatchedFieldsReportedAsSkipped(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['labels' => ['required' => false, 'name' => 'Labels']]);
        $this->issueTracker->expects($this->once())->method('update');

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput(
            'SCI-71',
            fieldsOption: 'labels=Bug;unknownField=val'
        ));

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['unknownField'], $response->skippedOptionalFields);
    }

    public function testErrorNoFieldsToUpdate(): void
    {
        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71'));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.update.error_no_fields', $response->getError());
    }

    public function testErrorEmptySummary(): void
    {
        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: '  '));

        $this->assertFalse($response->isSuccess());
    }

    public function testErrorEditmetaFetchFailure(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willThrowException(new ApiException('Not found', 'HTTP 404', 404));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title'));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.update.error_editmeta', $response->getError());
    }

    public function testErrorEditmetaThrowableFailure(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willThrowException(new \RuntimeException('Network error'));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title'));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.update.error_editmeta', $response->getError());
    }

    public function testErrorUpdateApiFailure(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->willThrowException(new ApiException('Forbidden', 'HTTP 403', 403));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title'));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.update.error_update', $response->getError());
    }

    public function testErrorUpdateThrowableFailure(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->willThrowException(new \RuntimeException('Timeout'));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title'));

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.update.error_update', $response->getError());
    }

    public function testEmptyDescriptionIsIgnored(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->with('SCI-71', $this->callback(fn ($f) => ! isset($f['description']) && $f['summary'] === 'Title'));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title', descriptionOption: '  '));

        $this->assertTrue($response->isSuccess());
    }

    public function testEmptyFieldsOptionIsIgnored(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->issueTracker->expects($this->once())->method('update');

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title', fieldsOption: '  '));

        $this->assertTrue($response->isSuccess());
    }

    public function testApiExceptionWithEmptyTechnicalDetails(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willThrowException(new ApiException('Not found', '', 404));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title'));

        $this->assertFalse($response->isSuccess());
    }

    public function testUpdateApiExceptionWithEmptyTechnicalDetails(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('getEditMetaFields')
            ->willReturn(['summary' => ['required' => true, 'name' => 'Summary']]);
        $this->issueTracker->expects($this->once())
            ->method('update')
            ->willThrowException(new ApiException('Error', '', 400));

        $handler = $this->createHandler();
        $response = $handler->handle(new ItemUpdateInput('SCI-71', summary: 'Title'));

        $this->assertFalse($response->isSuccess());
    }
}
