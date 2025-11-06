<?php

namespace App\Tests;

use App\Service\GitRepository;
use App\Service\JiraService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

abstract class CommandTestCase extends TestCase
{
    protected GitRepository $gitRepository;
    protected JiraService $jiraService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->jiraService = $this->createMock(JiraService::class);
    }
    protected function callPrivateMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
