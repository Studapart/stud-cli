<?php

namespace App\Handler;

use App\Service\GithubProvider;
use App\Service\GitRepository;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UpdateHandler
{
    public function __construct(
        private readonly GitRepository $gitRepository,
        private readonly string $currentVersion,
        private readonly string $binaryPath,
        private ?string $gitToken = null,
        private ?HttpClientInterface $httpClient = null
    ) {
    }

    public function handle(SymfonyStyle $io): int
    {
        $io->section('Checking for updates');

        // Get the actual binary path
        $binaryPath = $this->getBinaryPath();
        
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Binary path: {$binaryPath}</>");
        }

        // Get repository owner and name from git remote
        $repoOwner = $this->gitRepository->getRepositoryOwner();
        $repoName = $this->gitRepository->getRepositoryName();

        if (!$repoOwner || !$repoName) {
            $io->error([
                'Could not determine repository owner or name from git remote.',
                'Please ensure your repository has a remote named "origin" configured.',
            ]);
            return 1;
        }

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Detected repository: {$repoOwner}/{$repoName}</>");
            $io->writeln("  <fg=gray>Current version: {$this->currentVersion}</>");
        }

        // Create GitHub client (use token if available for private repositories)
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'stud-cli',
        ];
        
        if ($this->gitToken) {
            $headers['Authorization'] = 'Bearer ' . $this->gitToken;
        }
        
        $client = $this->httpClient ?? HttpClient::createForBaseUri('https://api.github.com', [
            'headers' => $headers,
        ]);

        $githubProvider = new GithubProvider($this->gitToken ?? '', $repoOwner, $repoName, $client);

        try {
            $release = $githubProvider->getLatestRelease();
        } catch (\Exception $e) {
            // Check if it's a 404 (no releases found)
            if (str_contains($e->getMessage(), 'Status: 404')) {
                $io->warning([
                    'No releases found for this repository.',
                    'The repository may not have any published releases yet.',
                ]);
                return 0;
            }
            
            $io->error([
                'Failed to fetch latest release information.',
                'Error: ' . $e->getMessage(),
            ]);
            return 1;
        }

        $latestVersion = ltrim($release['tag_name'], 'v'); // Remove 'v' prefix if present
        $currentVersion = ltrim($this->currentVersion, 'v');

        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Latest version: {$latestVersion}</>");
        }

        // Compare versions
        if (version_compare($latestVersion, $currentVersion, '<=')) {
            $io->success("You are already on the latest version ({$this->currentVersion}).");
            return 0;
        }

        $io->text("A new version ({$release['tag_name']}) is available. Updating...");

        // Find the stud.phar asset (could be stud.phar or stud-VERSION.phar)
        $pharAsset = null;
        foreach ($release['assets'] ?? [] as $asset) {
            $assetName = $asset['name'];
            // Match stud.phar or stud-VERSION.phar pattern
            if ($assetName === 'stud.phar' || 
                (str_starts_with($assetName, 'stud-') && str_ends_with($assetName, '.phar'))) {
                $pharAsset = $asset;
                break;
            }
        }

        if (!$pharAsset) {
            $io->error([
                'Could not find stud.phar asset in the latest release.',
                'Release assets: ' . implode(', ', array_column($release['assets'] ?? [], 'name')),
            ]);
            return 1;
        }

        // Download to temporary file
        $tempFile = sys_get_temp_dir() . '/stud.phar.new';
        if ($io->isVerbose()) {
            $io->writeln("  <fg=gray>Downloading from: {$pharAsset['browser_download_url']}</>");
        }

        try {
            $downloadClient = $this->httpClient ?? HttpClient::create();
            $response = $downloadClient->request('GET', $pharAsset['browser_download_url']);
            file_put_contents($tempFile, $response->getContent());
        } catch (\Exception $e) {
            $io->error([
                'Failed to download the new version.',
                'Error: ' . $e->getMessage(),
            ]);
            return 1;
        }

        // Check if current binary is writable
        if (!is_writable($binaryPath)) {
            $io->error([
                'Update failed: The file is not writable.',
                'Please re-run with elevated privileges: sudo stud update',
            ]);
            @unlink($tempFile);
            return 1;
        }

        // Replace the binary
        try {
            rename($tempFile, $binaryPath);
            chmod($binaryPath, 0755);
        } catch (\Exception $e) {
            $io->error([
                'Failed to replace the binary.',
                'Error: ' . $e->getMessage(),
            ]);
            @unlink($tempFile);
            return 1;
        }

        $io->success("✅ Update complete! You are now on {$release['tag_name']}.");

        return 0;
    }

    private function getBinaryPath(): string
    {
        // If running as PHAR, use Phar::running()
        if (class_exists('Phar') && \Phar::running(false)) {
            return \Phar::running(false);
        }

        // Otherwise, try to get path from ReflectionClass as suggested in ticket
        try {
            $reflection = new \ReflectionClass(\Castor\Console\Application::class);
            $filename = $reflection->getFileName();
            
            // If we're in a PHAR, the filename will be phar://...
            if (str_starts_with($filename, 'phar://')) {
                return $filename;
            }
        } catch (\ReflectionException $e) {
            // Fall through to next method
        }

        // Fallback: use the provided binary path
        return $this->binaryPath;
    }
}

