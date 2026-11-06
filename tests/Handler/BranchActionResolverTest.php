<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\BranchAction;
use App\Handler\BranchActionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BranchActionResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{array{local: array<string>, remote: array<string>}, array{action: string, branch: string}}>
     */
    public static function branchProvider(): iterable
    {
        yield 'generated branch exists locally' => [
            ['local' => ['feat/SCI-1-other', 'feat/SCI-1-title'], 'remote' => ['feat/SCI-1-title']],
            ['action' => BranchAction::SWITCH_LOCAL, 'branch' => 'feat/SCI-1-title'],
        ];
        yield 'generated branch exists only remotely' => [
            ['local' => ['feat/SCI-1-other'], 'remote' => ['feat/SCI-1-title']],
            ['action' => BranchAction::SWITCH_REMOTE, 'branch' => 'feat/SCI-1-title'],
        ];
        yield 'other local branch for the key' => [
            ['local' => ['fix/SCI-1-old'], 'remote' => ['fix/SCI-1-remote']],
            ['action' => BranchAction::SWITCH_LOCAL, 'branch' => 'fix/SCI-1-old'],
        ];
        yield 'other remote branch for the key' => [
            ['local' => [], 'remote' => ['fix/SCI-1-remote']],
            ['action' => BranchAction::SWITCH_REMOTE, 'branch' => 'fix/SCI-1-remote'],
        ];
        yield 'no existing branch' => [
            ['local' => [], 'remote' => []],
            ['action' => BranchAction::CREATE, 'branch' => 'feat/SCI-1-title'],
        ];
    }

    /**
     * @param array{local: array<string>, remote: array<string>} $existingBranches
     * @param array{action: string, branch: string} $expected
     */
    #[DataProvider('branchProvider')]
    public function testResolve(array $existingBranches, array $expected): void
    {
        $this->assertSame($expected, (new BranchActionResolver())->resolve('feat/SCI-1-title', $existingBranches));
    }
}
