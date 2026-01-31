<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\ValidationResult;
use App\Service\ConfigValidator;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;
    private GitRepository&MockObject $gitRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->validator = new ConfigValidator($this->logger, $this->translator, $this->gitRepository);
    }

    public function testValidateCommandRequirementsWithAllKeysPresent(): void
    {
        $globalConfig = [
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token123',
        ];
        $projectConfig = [];

        $result = $this->validator->validateCommandRequirements('items:list', $globalConfig, $projectConfig);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->canProceed);
        $this->assertEmpty($result->missingGlobalKeys);
        $this->assertEmpty($result->missingProjectKeys);
    }

    public function testValidateCommandRequirementsWithMissingGlobalKeys(): void
    {
        $globalConfig = [
            'JIRA_URL' => 'https://example.atlassian.net',
            // Missing JIRA_EMAIL and JIRA_API_TOKEN
        ];
        $projectConfig = [];

        $result = $this->validator->validateCommandRequirements('items:list', $globalConfig, $projectConfig);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->canProceed);
        $this->assertContains('JIRA_EMAIL', $result->missingGlobalKeys);
        $this->assertContains('JIRA_API_TOKEN', $result->missingGlobalKeys);
        $this->assertEmpty($result->missingProjectKeys);
    }

    public function testValidateCommandRequirementsWithMissingProjectKeys(): void
    {
        $globalConfig = [
            'JIRA_URL' => 'https://example.atlassian.net',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token123',
        ];
        $projectConfig = [
            // Missing baseBranch
        ];

        $result = $this->validator->validateCommandRequirements('items:start', $globalConfig, $projectConfig);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->canProceed);
        $this->assertEmpty($result->missingGlobalKeys);
        $this->assertContains('baseBranch', $result->missingProjectKeys);
    }

    public function testValidateCommandRequirementsWithUnknownCommand(): void
    {
        $globalConfig = [];
        $projectConfig = [];

        $result = $this->validator->validateCommandRequirements('unknown:command', $globalConfig, $projectConfig);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->canProceed);
        $this->assertEmpty($result->missingGlobalKeys);
        $this->assertEmpty($result->missingProjectKeys);
    }

    public function testValidateCommandRequirementsWithEmptyValues(): void
    {
        $globalConfig = [
            'JIRA_URL' => '',
            'JIRA_EMAIL' => '   ',
            'JIRA_API_TOKEN' => null,
        ];
        $projectConfig = [];

        $result = $this->validator->validateCommandRequirements('items:list', $globalConfig, $projectConfig);

        $this->assertFalse($result->canProceed);
        $this->assertNotEmpty($result->missingGlobalKeys);
    }

    public function testAutoDetectBaseBranch(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop', 'main', 'feature/test']);

        $result = $this->validator->autoDetectKey('baseBranch');

        $this->assertSame('develop', $result);
    }

    public function testAutoDetectBaseBranchReturnsNullWhenNotFound(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['feature/test', 'bugfix/fix']);

        $result = $this->validator->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }

    public function testAutoDetectBaseBranchReturnsNullWhenGitRepositoryIsNull(): void
    {
        $validator = new ConfigValidator($this->logger, $this->translator, null);

        $result = $validator->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }

    public function testAutoDetectKeyWithUnknownKey(): void
    {
        $result = $this->validator->autoDetectKey('unknown_key');

        $this->assertNull($result);
    }

    public function testPromptForMissingKeysWithAutoDetection(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['develop']);

        $this->translator->method('trans')
            ->willReturnCallback(function ($key, $params = []) {
                if ($key === 'config.auto_detected') {
                    return "Auto-detected {$params['key']}: {$params['value']}";
                }

                return $key;
            });

        $this->logger->expects($this->once())
            ->method('note');

        $result = $this->validator->promptForMissingKeys(['baseBranch'], 'project');

        $this->assertArrayHasKey('baseBranch', $result);
        $this->assertSame('develop', $result['baseBranch']);
    }

    public function testPromptForMissingKeysWithoutAutoDetection(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn([]);

        $this->translator->method('trans')
            ->willReturn('Prompt text');

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('custom-branch');

        $result = $this->validator->promptForMissingKeys(['baseBranch'], 'project');

        $this->assertArrayHasKey('baseBranch', $result);
        $this->assertSame('custom-branch', $result['baseBranch']);
    }

    public function testHasMissingKeys(): void
    {
        $result = new ValidationResult(['key1'], [], false);

        $this->assertTrue($result->hasMissingKeys());
    }

    public function testHasMissingKeysReturnsFalseWhenNoMissingKeys(): void
    {
        $result = new ValidationResult([], [], true);

        $this->assertFalse($result->hasMissingKeys());
    }

    public function testAutoDetectBaseBranchHandlesException(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willThrowException(new \RuntimeException('Git error'));

        $result = $this->validator->autoDetectKey('baseBranch');

        $this->assertNull($result);
    }

    public function testFindMissingKeys(): void
    {
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('findMissingKeys');
        $method->setAccessible(true);

        $requiredKeys = ['key1', 'key2', 'key3'];
        $config = [
            'key1' => 'value1',
            'key2' => '',
            // key3 is missing
        ];

        $result = $method->invoke($this->validator, $requiredKeys, $config);

        $this->assertContains('key2', $result);
        $this->assertContains('key3', $result);
        $this->assertNotContains('key1', $result);
    }

    public function testFindMissingKeysWithWhitespaceOnly(): void
    {
        $reflection = new \ReflectionClass($this->validator);
        $method = $reflection->getMethod('findMissingKeys');
        $method->setAccessible(true);

        $requiredKeys = ['key1'];
        $config = [
            'key1' => '   ',
        ];

        $result = $method->invoke($this->validator, $requiredKeys, $config);

        $this->assertContains('key1', $result);
    }

    public function testPromptForMissingKeysWithEmptyValue(): void
    {
        $this->translator->method('trans')
            ->willReturn('Prompt text');

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('');

        $result = $this->validator->promptForMissingKeys(['testKey'], 'global');

        $this->assertArrayNotHasKey('testKey', $result);
    }

    public function testPromptForMissingKeysWithNullValue(): void
    {
        $this->translator->method('trans')
            ->willReturn('Prompt text');

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn(null);

        $result = $this->validator->promptForMissingKeys(['testKey'], 'global');

        $this->assertArrayNotHasKey('testKey', $result);
    }

    public function testPromptForMissingKeysWithWhitespaceValue(): void
    {
        $this->translator->method('trans')
            ->willReturn('Prompt text');

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('   ');

        $result = $this->validator->promptForMissingKeys(['testKey'], 'global');

        $this->assertArrayNotHasKey('testKey', $result);
    }

    public function testPromptForMissingKeysWithValidValue(): void
    {
        $this->translator->method('trans')
            ->willReturn('Prompt text');

        $this->logger->expects($this->once())
            ->method('ask')
            ->willReturn('valid_value');

        $result = $this->validator->promptForMissingKeys(['testKey'], 'global');

        $this->assertArrayHasKey('testKey', $result);
        $this->assertSame('valid_value', $result['testKey']);
    }

    public function testAutoDetectBaseBranchWithMain(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['main', 'develop']);

        $result = $this->validator->autoDetectKey('baseBranch');

        // Should return 'develop' (first in priority order)
        $this->assertSame('develop', $result);
    }

    public function testAutoDetectBaseBranchWithMaster(): void
    {
        $this->gitRepository->method('getAllRemoteBranches')
            ->with('origin')
            ->willReturn(['master', 'feature/test']);

        $result = $this->validator->autoDetectKey('baseBranch');

        $this->assertSame('master', $result);
    }

    public function testAutoDetectBaseBranchWithNullGitRepository(): void
    {
        // Create validator without gitRepository
        $validator = new ConfigValidator($this->logger, $this->translator, null);

        $reflection = new \ReflectionClass($validator);
        $method = $reflection->getMethod('autoDetectBaseBranch');
        $method->setAccessible(true);

        $result = $method->invoke($validator);

        $this->assertNull($result);
    }
}
