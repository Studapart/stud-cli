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
        
        // Create a real TranslationService with English translations for tests
        $translationsPath = __DIR__ . '/../src/resources/translations';
        $this->translationService = new TranslationService('en', $translationsPath);
    }
    protected function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
