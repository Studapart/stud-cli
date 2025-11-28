<?php

namespace App\Tests;

use App\Service\GitRepository;
use App\Service\JiraService;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class CommandTestCase extends TestCase
{
    protected GitRepository $gitRepository;
    protected JiraService $jiraService;
    protected TranslationService $translationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->jiraService = $this->createMock(JiraService::class);
        
        // Mock TranslationService to avoid file system dependencies in unit tests
        // Note: TranslationServiceTest uses real instances for integration testing
        $this->translationService = $this->createMock(TranslationService::class);
        
        // Set up default mock behavior: return the key as the value for simple testing
        $this->translationService->method('trans')
            ->willReturnCallback(function ($id, $parameters = []) {
                // Return a simple representation for testing
                return $id . (empty($parameters) ? '' : ' ' . json_encode($parameters));
            });
        
        $this->translationService->method('getLocale')
            ->willReturn('en');
    }
    protected function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
