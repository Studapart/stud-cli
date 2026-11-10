<?php

namespace App\Tests\Service;

use App\Service\FileSystem;
use App\Service\PortableUpdateService;
use App\Service\Prompt\PromptInterface;
use App\Service\UpdateFileService;
use App\Service\UpdateInstaller;
use App\Service\UpdateRepositoryContext;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UpdateInstallerTest extends TestCase
{
    public function testPortableServiceBuildsPortableUpdateServiceForRepository(): void
    {
        $installer = new UpdateInstaller(
            $this->createStub(UpdateFileService::class),
            $this->createStub(PromptInterface::class),
            $this->createStub(FileSystem::class),
            $this->createStub(HttpClientInterface::class),
        );

        $service = $installer->portableService(new UpdateRepositoryContext('owner', 'repo', 'token'));

        $this->assertInstanceOf(PortableUpdateService::class, $service);
    }
}
