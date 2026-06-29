<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Exception\LinearTypeLabelException;
use App\Service\BranchNameGenerator;
use App\Service\LinearApiClient;
use App\Service\LinearTypeLabelResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LinearTypeLabelResolverTest extends TestCase
{
    private LinearApiClient&MockObject $linearApiClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
    }

    public function testResolveBranchPrefixMapsBugToFix(): void
    {
        $config = $this->linearConfig();

        $result = $this->createResolver()->resolveBranchPrefix(['Bug', 'DX'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FIX, $result['prefix']);
        $this->assertSame('Bug', $result['matchedLabel']);
        $this->assertNull($result['warning']);
    }

    public function testResolveBranchPrefixUsesUnknownLabelWarning(): void
    {
        $config = $this->linearConfig([
            ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES => [
                'Story' => 'feat',
            ],
        ]);

        $this->linearApiClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', false)
            ->willReturn([
                [
                    'id' => 'group-1',
                    'name' => 'Type',
                    'labels' => [
                        ['id' => 'label-spike', 'name' => 'Spike'],
                    ],
                ],
            ]);

        $result = $this->createResolver($this->linearApiClient)->resolveBranchPrefix(['Spike'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertSame('Spike', $result['matchedLabel']);
        $this->assertInstanceOf(MessageRef::class, $result['warning']);
        $this->assertSame('item.start.linear_unknown_type_label', $result['warning']->key);
    }

    public function testResolveBranchPrefixUsesNoTypeLabelWarning(): void
    {
        $config = $this->linearConfig();

        $result = $this->createResolver()->resolveBranchPrefix(['DX'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertNull($result['matchedLabel']);
        $this->assertInstanceOf(MessageRef::class, $result['warning']);
        $this->assertSame('item.start.linear_no_type_label', $result['warning']->key);
    }

    public function testResolveBranchPrefixDefaultsWhenGroupMissing(): void
    {
        $result = $this->createResolver()->resolveBranchPrefix(['Bug'], [], 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertNull($result['matchedLabel']);
        $this->assertNull($result['warning']);
    }

    public function testResolveBranchPrefixFetchesTypeLabelsFromApi(): void
    {
        $config = $this->linearConfig([
            ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES => [
                'Bug' => 'fix',
            ],
        ]);

        $this->linearApiClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', false)
            ->willReturn([
                [
                    'id' => 'group-1',
                    'name' => 'Type',
                    'labels' => [
                        ['id' => 'label-bug', 'name' => 'Bug'],
                        ['id' => 'label-spike', 'name' => 'Spike'],
                    ],
                ],
            ]);

        $result = $this->createResolver($this->linearApiClient)->resolveBranchPrefix(['Spike'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertSame('Spike', $result['matchedLabel']);
        $this->assertSame('item.start.linear_unknown_type_label', $result['warning']?->key);
    }

    public function testResolveTypeLabelIdReturnsChildLabelId(): void
    {
        $config = $this->linearConfig();

        $this->linearApiClient->expects($this->once())
            ->method('resolveLabelIds')
            ->with('SCI', ['Bug'], 'group-1')
            ->willReturn(['label-bug']);

        $labelId = $this->createResolver($this->linearApiClient)->resolveTypeLabelId('Bug', $config, 'SCI');

        $this->assertSame('label-bug', $labelId);
    }

    public function testResolveTypeLabelIdThrowsWhenLabelMissing(): void
    {
        $config = $this->linearConfig();

        $this->linearApiClient->expects($this->once())
            ->method('resolveLabelIds')
            ->with('SCI', ['Missing'], 'group-1')
            ->willReturn([]);

        $this->expectException(LinearTypeLabelException::class);
        $this->createResolver($this->linearApiClient)->resolveTypeLabelId('Missing', $config, 'SCI');
    }

    public function testResolveTypeLabelIdThrowsWhenGroupNotConfigured(): void
    {
        $this->expectException(LinearTypeLabelException::class);
        $this->createResolver($this->linearApiClient)->resolveTypeLabelId('Bug', [], 'SCI');
    }

    public function testResolveTypeLabelIdThrowsWhenClientMissing(): void
    {
        $this->expectException(LinearTypeLabelException::class);
        $this->createResolver()->resolveTypeLabelId('Bug', $this->linearConfig(), 'SCI');
    }

    public function testResolveTypeLabelIdGroupNotConfiguredUsesMessageRefKey(): void
    {
        try {
            $this->createResolver($this->linearApiClient)->resolveTypeLabelId('Bug', [], 'SCI');
            $this->fail('Expected LinearTypeLabelException');
        } catch (LinearTypeLabelException $e) {
            $this->assertSame('item.create.linear_type_group_not_configured', $e->messageRef->key);
        }
    }

    public function testResolveTypeLabelIdLabelNotFoundUsesMessageRefKey(): void
    {
        $config = $this->linearConfig();

        $this->linearApiClient->expects($this->once())
            ->method('resolveLabelIds')
            ->with('SCI', ['Missing'], 'group-1')
            ->willReturn([]);

        try {
            $this->createResolver($this->linearApiClient)->resolveTypeLabelId('Missing', $config, 'SCI');
            $this->fail('Expected LinearTypeLabelException');
        } catch (LinearTypeLabelException $e) {
            $this->assertSame('item.create.linear_type_label_not_found', $e->messageRef->key);
            $this->assertSame('Missing', $e->messageRef->parameters['type'] ?? null);
        }
    }

    public function testResolveBranchPrefixFallsBackToPrefixMapWhenTeamKeyMissing(): void
    {
        $config = $this->linearConfig();

        $result = $this->createResolver()->resolveBranchPrefix(['Bug'], $config, null);

        $this->assertSame(BranchNameGenerator::PREFIX_FIX, $result['prefix']);
        $this->assertSame('Bug', $result['matchedLabel']);
    }

    public function testResolveBranchPrefixCachesTypeLabelNamesFromApi(): void
    {
        $config = $this->linearConfig();

        $this->linearApiClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', false)
            ->willReturn([
                [
                    'id' => 'group-1',
                    'name' => 'Type',
                    'labels' => [
                        ['id' => 'label-bug', 'name' => 'Bug'],
                    ],
                ],
            ]);

        $resolver = $this->createResolver($this->linearApiClient);
        $resolver->resolveBranchPrefix(['Bug'], $config, 'SCI');
        $result = $resolver->resolveBranchPrefix(['Bug'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FIX, $result['prefix']);
    }

    public function testResolveBranchPrefixSkipsNonMatchingLabelGroups(): void
    {
        $config = $this->linearConfig();

        $this->linearApiClient->expects($this->once())
            ->method('getTeamLabelGroups')
            ->with('SCI', false)
            ->willReturn([
                [
                    'id' => 'other-group',
                    'name' => 'Other',
                    'labels' => [
                        ['id' => 'label-bug', 'name' => 'Bug'],
                    ],
                ],
            ]);

        $result = $this->createResolver($this->linearApiClient)->resolveBranchPrefix(['Bug'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FIX, $result['prefix']);
    }

    public function testResolveBranchPrefixIgnoresInvalidIssueLabels(): void
    {
        $config = $this->linearConfig();

        $result = $this->createResolver()->resolveBranchPrefix(['   ', '', 'Bug'], $config, null);

        $this->assertSame(BranchNameGenerator::PREFIX_FIX, $result['prefix']);
        $this->assertSame('Bug', $result['matchedLabel']);
    }

    public function testResolveBranchPrefixDefaultsWhenGroupIdEmpty(): void
    {
        $config = $this->linearConfig([
            ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID => '   ',
        ]);

        $result = $this->createResolver()->resolveBranchPrefix(['Bug'], $config, 'SCI');

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertNull($result['warning']);
    }

    public function testResolveBranchPrefixUsesEmptyPrefixMapWhenConfigInvalid(): void
    {
        $config = $this->linearConfig([
            ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES => 'invalid',
        ]);

        $result = $this->createResolver()->resolveBranchPrefix(['Bug'], $config, null);

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertNull($result['matchedLabel']);
        $this->assertSame('item.start.linear_no_type_label', $result['warning']?->key);
    }

    public function testMapLabelNameToPrefixTreatsBlankPrefixAsUnknown(): void
    {
        $resolver = $this->createResolver();
        $method = new \ReflectionMethod(LinearTypeLabelResolver::class, 'mapLabelNameToPrefix');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($resolver, 'Bug', ['Bug' => '   ']));
    }

    public function testResolveBranchPrefixIgnoresInvalidPrefixMapEntries(): void
    {
        $config = $this->linearConfig([
            ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES => [
                '' => 'fix',
                'Bug' => '',
                123 => 'feat',
                'Story' => 'feat',
            ],
        ]);

        $result = $this->createResolver()->resolveBranchPrefix(['Story'], $config, null);

        $this->assertSame(BranchNameGenerator::PREFIX_FEAT, $result['prefix']);
        $this->assertSame('Story', $result['matchedLabel']);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function linearConfig(array $overrides = []): array
    {
        return array_merge([
            ProjectStudConfigKeys::LINEAR_TYPE_LABEL_GROUP_ID => 'group-1',
            ProjectStudConfigKeys::LINEAR_TYPE_BRANCH_PREFIXES => [
                'Bug' => 'fix',
                'Story' => 'feat',
                'Task' => 'chore',
            ],
        ], $overrides);
    }

    private function createResolver(?LinearApiClient $client = null): LinearTypeLabelResolver
    {
        return new LinearTypeLabelResolver($client);
    }
}
