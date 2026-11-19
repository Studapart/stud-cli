<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\IssueCreationState;
use App\DTO\ItemCreateInput;
use App\Service\DurationParser;
use App\Service\FieldsParser;
use App\Service\ItemCreateFieldsOptionApplier;
use PHPUnit\Framework\TestCase;

class ItemCreateFieldsOptionApplierTest extends TestCase
{
    private const FIELDS_META = [
        'labels' => ['required' => false, 'name' => 'Labels'],
        '10010' => ['required' => false, 'name' => 'Story Points'],
    ];

    /**
     * @param list<string> $defaultLabels
     */
    private function createApplier(array $defaultLabels = []): ItemCreateFieldsOptionApplier
    {
        return new ItemCreateFieldsOptionApplier(new FieldsParser(new DurationParser()), $defaultLabels);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createState(array $fields = []): IssueCreationState
    {
        return new IssueCreationState('PROJ', '10001', self::FIELDS_META, [], $fields);
    }

    private function input(?string $fieldsOption = null): ItemCreateInput
    {
        return new ItemCreateInput('PROJ', 'Story', 'Summary', null, fieldsOption: $fieldsOption);
    }

    public function testApplyReturnsEmptyAndLeavesStateWhenNoFieldsAndNoDefaultLabels(): void
    {
        $state = $this->createState(['title' => 'Summary']);

        $this->assertSame([], $this->createApplier()->apply($state, $this->input()));
        $this->assertSame(['title' => 'Summary'], $state->fields);
        $this->assertSame([], $state->fieldInputsForUpdate);
    }

    public function testApplyMergesMatchedFieldsAndReturnsUnmatched(): void
    {
        $state = $this->createState();

        $skipped = $this->createApplier()->apply($state, $this->input('Story Points=3;unknown=x'));

        $this->assertSame(['unknown'], $skipped);
        $this->assertSame(['customfield_10010' => '3'], $state->fields);
        $this->assertSame(['Story Points' => '3', 'unknown' => 'x'], $state->fieldInputsForUpdate);
        $this->assertSame(['customfield_10010' => 'Story Points'], $state->payloadKeyToInputKey);
    }

    public function testApplyUsesPreParsedFieldsMap(): void
    {
        $state = $this->createState();
        $input = new ItemCreateInput('PROJ', 'Story', 'Summary', null, fieldsMap: ['labels' => ['a', 'b']]);

        $this->assertSame([], $this->createApplier(['Default'])->apply($state, $input));
        $this->assertSame(['labels' => ['a', 'b']], $state->fields);
    }

    public function testApplyAddsDefaultLabelsWhenNoneProvided(): void
    {
        $state = $this->createState();

        $this->createApplier(['AI-Generated'])->apply($state, $this->input());

        $this->assertSame(['labels' => ['AI-Generated']], $state->fields);
        $this->assertSame(['labels' => 'labels'], $state->payloadKeyToInputKey);
    }

    public function testApplyDropsBlankLabelsOptionWithoutApplyingDefault(): void
    {
        $state = $this->createState();

        $this->assertSame([], $this->createApplier(['AI-Generated'])->apply($state, $this->input('Labels= ')));
        $this->assertSame([], $state->fields);
    }

    public function testApplySkipsDefaultLabelsWhenStateAlreadyHasLabels(): void
    {
        $state = $this->createState(['Labels' => ['existing']]);

        $this->assertSame([], $this->createApplier(['AI-Generated'])->apply($state, $this->input()));
        $this->assertSame(['Labels' => ['existing']], $state->fields);
    }
}
