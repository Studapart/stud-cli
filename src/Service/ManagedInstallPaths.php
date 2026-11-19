<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Paths of the managed install layout under $HOME: versioned copies live under the root, and
 * ~/.local/bin/stud is a symlink to the active copy.
 */
final class ManagedInstallPaths
{
    /** A release version used as a path segment: no separators, so it cannot leave the directory it is joined to. */
    private const VERSION_TOKEN = '/\A[0-9A-Za-z][0-9A-Za-z.+-]*\z/';

    private function __construct(
        private readonly ?string $home,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return new self(is_string($home) && $home !== '' ? rtrim($home, '/') : null);
    }

    public static function isVersion(string $version): bool
    {
        return preg_match(self::VERSION_TOKEN, $version) === 1;
    }

    public static function fromHome(string $home): self
    {
        return new self(rtrim($home, '/'));
    }

    public function isConfigured(): bool
    {
        return $this->home !== null;
    }

    public function root(): string
    {
        return $this->home() . '/.local/share/stud-portable';
    }

    public function managedSymlink(): string
    {
        return $this->home() . '/.local/bin/stud';
    }

    public function pharDirectory(): string
    {
        return $this->root() . '/phar';
    }

    public function pharFile(string $version): string
    {
        return $this->pharDirectory() . '/' . $version . '-stud.phar';
    }

    public function cleanupMarker(): string
    {
        return $this->root() . '/.cleanup-pending';
    }

    public function isWithin(string $directory, string $path): bool
    {
        return str_starts_with($this->resolve($path), $this->resolve($directory) . '/');
    }

    /**
     * True when the symlink at $symlinkPath points into the managed root, including a dangling target.
     */
    public function isManagedLink(string $symlinkPath): bool
    {
        $target = is_link($symlinkPath) ? readlink($symlinkPath) : false;
        if ($target === false) {
            return false;
        }

        $absoluteTarget = str_starts_with($target, '/') ? $target : dirname($symlinkPath) . '/' . $target;

        return $this->isWithin($this->root(), $absoluteTarget);
    }

    /**
     * The entry under <root>/<group>/ that the managed symlink resolves to, or null when it is not a managed target.
     */
    public function activeEntry(): ?string
    {
        $target = realpath($this->managedSymlink());
        $root = realpath($this->root());
        if ($target === false || $root === false || ! str_starts_with($target, $root . '/')) {
            return null;
        }

        $segments = explode('/', substr($target, strlen($root) + 1));
        if (count($segments) < 2) {
            return null;
        }

        return $root . '/' . $segments[0] . '/' . $segments[1];
    }

    private function home(): string
    {
        return $this->home ?? throw new \LogicException('HOME is not set, so the managed install layout is unavailable.');
    }

    /**
     * Resolves symlinks when the path exists; otherwise removes "." and ".." segments lexically.
     */
    private function resolve(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return $resolved;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
