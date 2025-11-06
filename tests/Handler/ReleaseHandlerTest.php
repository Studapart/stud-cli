<?php

namespace App\Tests\Handler;

use App\Handler\ReleaseHandler;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Style\SymfonyStyle;

class ReleaseHandlerTest extends CommandTestCase
{
    use ProphecyTrait;

    public function testHandle(): void
    {
        $gitRepository = $this->prophesize(GitRepository::class);
        $io = $this->prophesize(SymfonyStyle::class);

        $version = '1.2.3';
        $releaseBranch = 'release/v' . $version;

        $gitRepository->fetch()->shouldBeCalled();
        $gitRepository->createBranch($releaseBranch, 'origin/develop')->shouldBeCalled();
        $gitRepository->run('composer update --lock')->shouldBeCalled();
        $gitRepository->run('composer dump-config')->shouldBeCalled();
        $gitRepository->add(['composer.json', 'composer.lock', 'config/app.php'])->shouldBeCalled();
        $gitRepository->commit('chore(Version): Bump version to ' . $version)->shouldBeCalled();

        // Create a dummy composer.json
        file_put_contents('composer.json', json_encode(['version' => '1.0.0']));

        $handler = new ReleaseHandler($gitRepository->reveal());
        $handler->handle($io->reveal(), $version);

        $composerJson = json_decode(file_get_contents('composer.json'), true);
        $this->assertSame($version, $composerJson['version']);

        // Clean up
        unlink('composer.json');
    }
}
