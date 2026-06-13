<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Config;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Config\ConfigLoader;

final class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    // -------------------------------------------------------------------------
    // Configuration value object
    // -------------------------------------------------------------------------

    public function testConfigurationExposesHost(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logFile: null,
        );

        $this->assertSame('127.0.0.1', $config->host);
    }

    public function testConfigurationExposesPort(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 9090,
            githubToken: 'token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logFile: null,
        );

        $this->assertSame(9090, $config->port);
    }

    public function testConfigurationExposesGithubToken(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'my-secret-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logFile: null,
        );

        $this->assertSame('my-secret-token', $config->githubToken);
    }

    public function testConfigurationExposesRepositories(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'token',
            repositories: ['owner/repo-a', 'owner/repo-b'],
            readyLabel: 'kanine: ready',
            logFile: null,
        );

        $this->assertSame(['owner/repo-a', 'owner/repo-b'], $config->repositories);
    }

    public function testConfigurationExposesReadyLabel(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'token',
            repositories: ['owner/repo'],
            readyLabel: 'my-label',
            logFile: null,
        );

        $this->assertSame('my-label', $config->readyLabel);
    }

    public function testConfigurationExposesLogFile(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logFile: '/var/log/kanine.log',
        );

        $this->assertSame('/var/log/kanine.log', $config->logFile);
    }

    public function testConfigurationLogFileCanBeNull(): void
    {
        $config = new Configuration(
            host: '127.0.0.1',
            port: 3737,
            githubToken: 'token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            logFile: null,
        );

        $this->assertNull($config->logFile);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: explicit path
    // -------------------------------------------------------------------------

    public function testLoaderLoadsConfigFromExplicitPath(): void
    {
        $yaml = $this->buildYaml(
            token: 'explicit-token',
            repositories: ['explicit/repo'],
            readyLabel: 'explicit: ready',
            host: '10.0.0.1',
            port: 4444,
        );
        $path = $this->writeYaml($yaml, 'explicit.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('explicit-token', $config->githubToken);
    }

    public function testLoaderLoadsRepositoriesFromExplicitPath(): void
    {
        $yaml = $this->buildYaml(
            token: 'tok',
            repositories: ['owner/alpha', 'owner/beta'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'explicit.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(['owner/alpha', 'owner/beta'], $config->repositories);
    }

    public function testLoaderLoadsSupervisorSettingsFromExplicitPath(): void
    {
        $yaml = $this->buildYaml(
            token: 'tok',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '192.168.1.1',
            port: 8080,
        );
        $path = $this->writeYaml($yaml, 'explicit.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('192.168.1.1', $config->host);
        $this->assertSame(8080, $config->port);
    }

    public function testLoaderLoadsReadyLabelFromExplicitPath(): void
    {
        $yaml = $this->buildYaml(
            token: 'tok',
            repositories: ['owner/repo'],
            readyLabel: 'custom: label',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'explicit.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('custom: label', $config->readyLabel);
    }

    public function testLoaderThrowsWhenExplicitPathDoesNotExist(): void
    {
        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nonexistent\.yaml/');

        $loader->load(explicitPath: $this->tempDir . '/nonexistent.yaml');
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: token resolution from environment
    // -------------------------------------------------------------------------

    public function testLoaderResolvesGithubTokenFromEnvironmentVariable(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            tokenEnv: 'KANINE_TEST_TOKEN',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'env-token.yaml');

        putenv('KANINE_TEST_TOKEN=env-provided-token');

        try {
            $loader = new ConfigLoader();
            $config = $loader->load(explicitPath: $path);

            $this->assertSame('env-provided-token', $config->githubToken);
        } finally {
            putenv('KANINE_TEST_TOKEN');
        }
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: fallback to defaults when no file is found
    // -------------------------------------------------------------------------

    public function testLoaderFallsBackToDefaultsWhenNoFileFound(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent-a.yaml', $this->tempDir . '/nonexistent-b.yaml'],
        );

        $config = $loader->load();

        $this->assertInstanceOf(Configuration::class, $config);
    }

    public function testLoaderDefaultHostIs127001(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame('127.0.0.1', $config->host);
    }

    public function testLoaderDefaultPortIs3737(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame(3737, $config->port);
    }

    public function testLoaderDefaultReadyLabel(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame('kanine: ready', $config->readyLabel);
    }

    public function testLoaderDefaultRepositoriesIsEmpty(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame([], $config->repositories);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: default path resolution order
    // -------------------------------------------------------------------------

    public function testLoaderLoadsFromFirstFoundDefaultPath(): void
    {
        $yaml = $this->buildYaml(
            token: 'first-found-token',
            repositories: ['first/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $firstPath  = $this->writeYaml($yaml, 'first.yaml');
        $secondPath = $this->tempDir . '/second.yaml';

        $loader = new ConfigLoader(
            defaultPaths: [$firstPath, $secondPath],
        );

        $config = $loader->load();

        $this->assertSame('first-found-token', $config->githubToken);
    }

    public function testLoaderSkipsMissingDefaultPathsAndUsesNextFound(): void
    {
        $yaml = $this->buildYaml(
            token: 'second-found-token',
            repositories: ['second/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $missingPath = $this->tempDir . '/missing.yaml';
        $secondPath  = $this->writeYaml($yaml, 'second.yaml');

        $loader = new ConfigLoader(
            defaultPaths: [$missingPath, $secondPath],
        );

        $config = $loader->load();

        $this->assertSame('second-found-token', $config->githubToken);
    }

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kanine-config-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $repositories
     */
    private function buildYaml(
        ?string $token,
        array $repositories,
        string $readyLabel,
        string $host,
        int $port,
        string $tokenEnv = 'GITHUB_TOKEN',
    ): string {
        $repoLines = '';
        foreach ($repositories as $repo) {
            $repoLines .= "    - {$repo}\n";
        }

        $tokenLine = $token !== null
            ? "  token: {$token}\n"
            : '';

        return <<<YAML
            github:
              repositories:
            {$repoLines}  token_env: {$tokenEnv}
              ready_label: "{$readyLabel}"
            {$tokenLine}
            supervisor:
              host: {$host}
              port: {$port}
            YAML;
    }

    private function writeYaml(string $content, string $filename): string
    {
        $path = $this->tempDir . '/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
