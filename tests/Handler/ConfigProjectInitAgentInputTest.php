<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Handler\ConfigProjectInitAgentInput;
use App\Response\ConfigProjectInitResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigProjectInitAgentInputTest extends TestCase
{
    public function testValidateAcceptsKnownKeys(): void
    {
        $this->assertNull((new ConfigProjectInitAgentInput())->validate([
            'projectKey' => 'SCI',
            'gitProvider' => 'github',
        ]));
    }

    public function testValidateReportsReservedKeysSortedBeforeUnknownKeys(): void
    {
        $response = (new ConfigProjectInitAgentInput())->validate([
            ProjectStudConfigKeys::MIGRATION_VERSION => '1',
            'nope' => true,
        ]);

        $this->assertErrorRef($response, 'config.project_init.reserved_keys', ['%keys%' => ProjectStudConfigKeys::MIGRATION_VERSION]);
    }

    public function testValidateReportsUnknownKeysSorted(): void
    {
        $response = (new ConfigProjectInitAgentInput())->validate(['zeta' => 1, 'alpha' => 2]);

        $this->assertErrorRef($response, 'config.project_init.unknown_keys', ['%keys%' => 'alpha, zeta']);
    }

    #[DataProvider('validTransitionIds')]
    public function testValidateAcceptsTransitionIdForms(mixed $transitionId): void
    {
        $this->assertNull((new ConfigProjectInitAgentInput())->validate(['transitionId' => $transitionId]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function validTransitionIds(): iterable
    {
        yield 'null' => [null];
        yield 'zero int' => [0];
        yield 'digit string with spaces' => [' 42 '];
    }

    #[DataProvider('invalidTransitionIds')]
    public function testValidateRejectsInvalidTransitionIds(mixed $transitionId): void
    {
        $response = (new ConfigProjectInitAgentInput())->validate(['transitionId' => $transitionId]);

        $this->assertErrorRef($response, 'config.project_init.invalid_transition_id');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTransitionIds(): iterable
    {
        yield 'negative int' => [-1];
        yield 'non-digit string' => ['abc'];
        yield 'float' => [1.5];
    }

    public function testPatchesCoerceValuesInFieldMapOrder(): void
    {
        $patches = (new ConfigProjectInitAgentInput())->patches([
            'issueTrackerProvider' => ' Linear ',
            'gitProvider' => ' GitHub ',
            'transitionId' => '7',
            'projectKey' => ' sci ',
            'linearTypeBranchPrefixes' => [' Bug ' => ' fix ', 'Empty' => ' ', 3 => 'x', 'NonString' => 1],
            'workItemLabels' => [' AI-Generated ', ''],
        ]);

        $this->assertSame(7, $patches['transitionId']);
        $this->assertSame('github', $patches['gitProvider']);
        $this->assertSame('linear', $patches['issueTrackerProvider']);
        $this->assertSame('sci', $patches['projectKey']);
        $this->assertSame(['Bug' => 'fix'], $patches['linearTypeBranchPrefixes']);
        $this->assertSame(['AI-Generated'], $patches['workItemLabels']);
    }

    public function testPatchesSkipNullBlankAndEmptyValues(): void
    {
        $patches = (new ConfigProjectInitAgentInput())->patches([
            'projectKey' => null,
            'baseBranch' => '   ',
            'linearTypeBranchPrefixes' => [],
            'pullRequestLabels' => [''],
        ]);

        $this->assertSame([], $patches);
    }

    public function testPatchesKeepNonStringValuesUnchanged(): void
    {
        $patches = (new ConfigProjectInitAgentInput())->patches([
            'gitProvider' => 5,
            'linearTypeBranchPrefixes' => 'not-a-map',
            'workItemLabels' => 'AI-Generated',
            'pullRequestLabels' => ['AI-Generated', 1],
        ]);

        $this->assertSame([
            'gitProvider' => 5,
            'linearTypeBranchPrefixes' => 'not-a-map',
            'workItemLabels' => 'AI-Generated',
            'pullRequestLabels' => ['AI-Generated', 1],
        ], $patches);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function assertErrorRef(?ConfigProjectInitResponse $response, string $key, array $parameters = []): void
    {
        $this->assertInstanceOf(ConfigProjectInitResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $message = $response->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $message);
        $this->assertSame($key, $message->key);
        $this->assertSame($parameters, $message->parameters);
    }
}
