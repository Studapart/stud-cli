<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CommandReferenceTableBuilder;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class CommandReferenceTableBuilderTest extends TestCase
{
    private CommandReferenceTableBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $translator = $this->createStub(TranslationService::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $key): string => 'translated:' . $key
        );

        $this->builder = new CommandReferenceTableBuilder($translator);
    }

    public function testBuildArgumentsRendersNoneWithoutMetadata(): void
    {
        $this->assertSame(['#### Arguments', '', 'None.', ''], $this->builder->buildArguments(null));
    }

    public function testBuildArgumentsEscapesPipesInArgumentRows(): void
    {
        $this->assertSame(
            ['#### Arguments', '', '| Argument |', '| --- |', '| `<plain\|markdown>` |', ''],
            $this->builder->buildArguments(['arguments' => ['<plain|markdown>']]),
        );
    }

    public function testBuildOptionsRendersNoneWithoutMetadataOrSchemaOptions(): void
    {
        $this->assertSame(['#### Options', '', 'None.', ''], $this->builder->buildOptions(null, [
            'parameters' => ['options' => []],
        ]));
    }

    public function testBuildOptionsRendersTranslatedMetadataRowsWithShortcuts(): void
    {
        $lines = $this->builder->buildOptions([
            'options' => [
                ['name' => '--project', 'shortcut' => '-p', 'description_key' => 'help.option_project', 'argument' => '<key>'],
                ['name' => '--all', 'shortcut' => null, 'description_key' => 'help.option_all', 'argument' => null],
            ],
        ], []);

        $this->assertSame([
            '#### Options',
            '',
            '| Option | Description |',
            '| --- | --- |',
            '| `--project <key><br>`-p <key>`` | translated:help.option_project |',
            '| `--all` | translated:help.option_all |',
            '',
        ], $lines);
    }

    public function testBuildOptionsFallsBackToSchemaOptions(): void
    {
        $this->assertSame([
            '#### Options',
            '',
            '| Option | Description |',
            '| --- | --- |',
            '| `custom-option` | See command schema. |',
            '',
        ], $this->builder->buildOptions(null, ['parameters' => ['options' => ['custom-option']]]));
    }

    public function testBuildAgentInputRendersNoPropertiesMessage(): void
    {
        $this->assertSame(
            ['#### Agent JSON Input', '', 'No input properties.', ''],
            $this->builder->buildAgentInput(['input' => ['properties' => []]]),
        );
    }

    public function testBuildAgentInputRendersTypesOptionalityAndDefaults(): void
    {
        $lines = $this->builder->buildAgentInput(['input' => ['properties' => [
            'compact' => ['type' => 'bool', 'optional' => true, 'default' => true],
            'fields' => ['type' => 'string|array|null', 'optional' => true, 'default' => ['labels' => ['a/b']]],
            'key' => ['type' => 'string'],
            'stream' => ['optional' => true, 'default' => fopen('php://memory', 'r')],
            'raw' => 'not-an-array',
        ]]]);

        $this->assertSame([
            '#### Agent JSON Input',
            '',
            '| Property | Type | Optional | Default |',
            '| --- | --- | --- | --- |',
            '| `compact` | `bool` | yes | `true` |',
            '| `fields` | `string\|array\|null` | yes | `{"labels":["a/b"]}` |',
            '| `key` | `string` | no | `` |',
            '| `stream` | `mixed` | yes | `` |',
            '| `raw` | `mixed` | no | `` |',
            '',
        ], $lines);
    }
}
