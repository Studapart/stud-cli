<?php

namespace App\Tests\Service;

use App\Service\ChangelogParser;
use PHPUnit\Framework\TestCase;

class ChangelogParserTest extends TestCase
{
    private ChangelogParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ChangelogParser();
    }

    public function testParseWithBreakingChanges(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.1] - 2025-01-01

### Breaking
- Rename command `issues:search` to `items:search` [SCI-2]

### Added
- New feature [TPW-1]

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.1');

        $this->assertTrue($result['hasBreaking']);
        $this->assertCount(1, $result['breakingChanges']);
        $this->assertStringContainsString('issues:search', $result['breakingChanges'][0]);
        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertCount(1, $result['sections']['added']);
    }

    public function testParseWithMultipleVersions(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.2] - 2025-01-03

### Added
- Feature in 1.0.2

## [1.0.1] - 2025-01-02

### Breaking
- Breaking change in 1.0.1

### Fixed
- Fix in 1.0.1

## [1.0.0] - 2025-01-01

### Added
- Initial release

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.2');

        $this->assertTrue($result['hasBreaking']);
        $this->assertCount(1, $result['breakingChanges']);
        $this->assertStringContainsString('Breaking change in 1.0.1', $result['breakingChanges'][0]);
        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertArrayHasKey('fixed', $result['sections']);
        $this->assertCount(1, $result['sections']['added']);
        $this->assertCount(1, $result['sections']['fixed']);
    }

    public function testParseStopsAtCurrentVersion(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.2] - 2025-01-03

### Added
- Feature in 1.0.2

## [1.0.1] - 2025-01-02

### Added
- Feature in 1.0.1

## [1.0.0] - 2025-01-01

### Added
- Should not be included

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.2');

        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertCount(2, $result['sections']['added']);
        $allItems = implode(' ', $result['sections']['added']);
        $this->assertStringNotContainsString('Should not be included', $allItems);
    }

    public function testParseWithVersionPrefixes(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.1] - 2025-01-01

### Added
- Feature

CHANGELOG;

        // Test with 'v' prefix in versions
        $result = $this->parser->parse($changelogContent, 'v1.0.0', 'v1.0.1');

        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertCount(1, $result['sections']['added']);
    }

    public function testParseWithEmptySections(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.1] - 2025-01-01

### Added

### Fixed

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.1');

        $this->assertFalse($result['hasBreaking']);
        $this->assertEmpty($result['breakingChanges']);
        $this->assertEmpty($result['sections']);
    }

    public function testParseSkipsVersionsOutsideRange(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.3] - 2025-01-03

### Added
- Should not be included

## [1.0.2] - 2025-01-02

### Added
- Should be included

## [1.0.1] - 2025-01-01

### Added
- Should be included

## [1.0.0] - 2025-01-01

### Added
- Should not be included

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.2');

        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertCount(2, $result['sections']['added']);
        $allItems = implode(' ', $result['sections']['added']);
        $this->assertStringNotContainsString('Should not be included', $allItems);
    }

    public function testParseStopsAtOlderVersion(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.2] - 2025-01-03

### Added
- Feature in 1.0.2

## [1.0.1] - 2025-01-02

### Added
- Feature in 1.0.1

## [0.9.9] - 2025-01-01

### Added
- Should not be included (older than current)

CHANGELOG;

        // Current version is 1.0.0, so 0.9.9 should stop parsing
        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.2');

        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertCount(2, $result['sections']['added']);
        $allItems = implode(' ', $result['sections']['added']);
        $this->assertStringNotContainsString('Should not be included', $allItems);
        $this->assertStringContainsString('1.0.2', $allItems);
        $this->assertStringContainsString('1.0.1', $allItems);
    }

    public function testGetSectionTitle(): void
    {
        $this->assertSame('### Added', $this->parser->getSectionTitle('added'));
        $this->assertSame('### Changed', $this->parser->getSectionTitle('changed'));
        $this->assertSame('### Fixed', $this->parser->getSectionTitle('fixed'));
        $this->assertSame('### Breaking', $this->parser->getSectionTitle('breaking'));
        $this->assertSame('### Deprecated', $this->parser->getSectionTitle('deprecated'));
        $this->assertSame('### Removed', $this->parser->getSectionTitle('removed'));
        $this->assertSame('### Security', $this->parser->getSectionTitle('security'));
        $this->assertSame('### Custom', $this->parser->getSectionTitle('custom'));
    }

    public function testGetSectionTitleIsCaseInsensitive(): void
    {
        $this->assertSame('### Added', $this->parser->getSectionTitle('ADDED'));
        $this->assertSame('### Breaking', $this->parser->getSectionTitle('BREAKING'));
    }

    public function testParseWithRegularSections(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.1] - 2025-01-01

### Added
- New feature [TPW-1]

### Fixed
- Bug fix [TPW-2]

### Changed
- Update to existing feature [TPW-3]

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.1');

        $this->assertFalse($result['hasBreaking']);
        $this->assertEmpty($result['breakingChanges']);
        $this->assertArrayHasKey('added', $result['sections']);
        $this->assertArrayHasKey('fixed', $result['sections']);
        $this->assertArrayHasKey('changed', $result['sections']);
        $this->assertCount(1, $result['sections']['added']);
        $this->assertCount(1, $result['sections']['fixed']);
        $this->assertCount(1, $result['sections']['changed']);
    }

    public function testParseWithMultipleBreakingChanges(): void
    {
        $changelogContent = <<<'CHANGELOG'
## [1.0.1] - 2025-01-01

### Breaking
- Command renamed: old:command to new:command
- Removed deprecated feature
- Changed API signature

CHANGELOG;

        $result = $this->parser->parse($changelogContent, '1.0.0', '1.0.1');

        $this->assertTrue($result['hasBreaking']);
        $this->assertCount(3, $result['breakingChanges']);
        $this->assertStringContainsString('Command renamed', $result['breakingChanges'][0]);
        $this->assertStringContainsString('Removed deprecated', $result['breakingChanges'][1]);
        $this->assertStringContainsString('Changed API', $result['breakingChanges'][2]);
    }

    public function testExtractVersionFromLine(): void
    {
        $parser = new ChangelogParser();
        
        $this->assertSame('1.0.0', $this->callPrivateMethod($parser, 'extractVersionFromLine', ['## [1.0.0] - 2025-01-01']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractVersionFromLine', ['### Added']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractVersionFromLine', ['- Feature']));
    }

    public function testIsInTargetVersion(): void
    {
        $parser = new ChangelogParser();
        
        $this->assertTrue($this->callPrivateMethod($parser, 'isInTargetVersion', ['1.0.1', '1.0.0', '1.0.2']));
        $this->assertTrue($this->callPrivateMethod($parser, 'isInTargetVersion', ['1.0.2', '1.0.0', '1.0.2']));
        $this->assertFalse($this->callPrivateMethod($parser, 'isInTargetVersion', ['1.0.0', '1.0.0', '1.0.2']));
        $this->assertFalse($this->callPrivateMethod($parser, 'isInTargetVersion', ['1.0.3', '1.0.0', '1.0.2']));
    }

    public function testExtractSectionFromLine(): void
    {
        $parser = new ChangelogParser();
        
        $this->assertSame('added', $this->callPrivateMethod($parser, 'extractSectionFromLine', ['### Added']));
        $this->assertSame('breaking', $this->callPrivateMethod($parser, 'extractSectionFromLine', ['### Breaking']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractSectionFromLine', ['## [1.0.0] - 2025-01-01']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractSectionFromLine', ['- Feature']));
    }

    public function testExtractItemFromLine(): void
    {
        $parser = new ChangelogParser();
        
        $this->assertSame('Feature added', $this->callPrivateMethod($parser, 'extractItemFromLine', ['- Feature added']));
        $this->assertSame('Bug fix', $this->callPrivateMethod($parser, 'extractItemFromLine', ['* Bug fix']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractItemFromLine', ['### Added']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractItemFromLine', ['## [1.0.0] - 2025-01-01']));
        $this->assertNull($this->callPrivateMethod($parser, 'extractItemFromLine', ['-   ']));
    }

    public function testNormalizeVersion(): void
    {
        $parser = new ChangelogParser();
        
        $this->assertSame('1.0.0', $this->callPrivateMethod($parser, 'normalizeVersion', ['1.0.0']));
        $this->assertSame('1.0.0', $this->callPrivateMethod($parser, 'normalizeVersion', ['v1.0.0']));
        $this->assertSame('1.0.0', $this->callPrivateMethod($parser, 'normalizeVersion', ['vv1.0.0']));
    }

    protected function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}

