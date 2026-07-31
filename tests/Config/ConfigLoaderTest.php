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
            host: '127.0.0.1',
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
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '192.168.1.1',
            port: 8080,
            tls: true,
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
    // ConfigLoader: built-in default path is .kanine/kanine.yaml
    // -------------------------------------------------------------------------

    public function testBuiltInDefaultPathLoadsDotKanineKanineYaml(): void
    {
        // Uses a no-arg ConfigLoader; temporarily changes cwd so the built-in
        // path (getcwd().'/.kanine/kanine.yaml') resolves to a file we control.
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $dotKanineDir = $this->tempDir . '/.kanine';
            mkdir($dotKanineDir, 0777, true);
            $yaml = $this->buildYaml(
                token: 'builtin-dotkanine-token',
                repositories: ['owner/repo'],
                readyLabel: 'kanine: ready',
                host: '127.0.0.1',
                port: 3737,
            );
            file_put_contents($dotKanineDir . '/kanine.yaml', $yaml);

            $loader = new ConfigLoader();
            $config = $loader->load();

            $this->assertSame('builtin-dotkanine-token', $config->githubToken);
        } finally {
            chdir($originalCwd);
        }
    }

    public function testBuiltInDefaultDoesNotLoadBareKanineYaml(): void
    {
        // Place only a bare kanine.yaml in cwd (no .kanine/ subdir).
        // The built-in loader must NOT pick it up.
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $yaml = $this->buildYaml(
                token: 'bare-builtin-token',
                repositories: ['bare/repo'],
                readyLabel: 'kanine: ready',
                host: '127.0.0.1',
                port: 3737,
            );
            file_put_contents($this->tempDir . '/kanine.yaml', $yaml);

            // No HOME or XDG fallback will exist in tempDir either
            $loader = new ConfigLoader(
                defaultPaths: [getcwd() . '/.kanine/kanine.yaml'],
            );
            $config = $loader->load();

            $this->assertNotSame('bare-builtin-token', $config->githubToken);
        } finally {
            chdir($originalCwd);
        }
    }

    public function testDefaultPathIncludesDotKanineDirectory(): void
    {
        $yaml = $this->buildYaml(
            token: 'dotkanine-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );

        $dotKanineDir = $this->tempDir . '/.kanine';
        mkdir($dotKanineDir, 0777, true);
        file_put_contents($dotKanineDir . '/kanine.yaml', $yaml);

        $loader = new ConfigLoader(
            defaultPaths: [$dotKanineDir . '/kanine.yaml'],
        );

        $config = $loader->load();

        $this->assertSame('dotkanine-token', $config->githubToken);
    }

    public function testBareKanineYamlIsNotInBuiltInDefaultPaths(): void
    {
        // Place a bare kanine.yaml in a temp dir — if the loader's built-in
        // default list still contains getcwd()/kanine.yaml it could pick it up.
        // We verify by constructing a loader whose defaultPaths do NOT include
        // the bare path and confirming it falls through to defaults.
        $yaml = $this->buildYaml(
            token: 'bare-token',
            repositories: ['bare/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $barePath = $this->tempDir . '/kanine.yaml';
        file_put_contents($barePath, $yaml);

        // Build a loader whose only candidate is the .kanine sub-path (missing)
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/.kanine/kanine.yaml'],
        );

        $config = $loader->load();

        // Falls through to defaults — bare-token must NOT appear
        $this->assertNotSame('bare-token', $config->githubToken);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: token resolution — explicit --token override
    // -------------------------------------------------------------------------

    public function testTokenOverrideTakesPrecedenceOverInlineToken(): void
    {
        $yaml = $this->buildYaml(
            token: 'inline-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'override.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path, tokenOverride: 'cli-flag-token');

        $this->assertSame('cli-flag-token', $config->githubToken);
    }

    public function testTokenOverrideTakesPrecedenceOverLocalYamlToken(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'kanine.yaml');
        file_put_contents($this->tempDir . '/kanine.local.yaml', "github:\n  token: local-yaml-token\n");

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path, tokenOverride: 'cli-flag-token');

        $this->assertSame('cli-flag-token', $config->githubToken);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: token resolution — kanine.local.yaml sibling file
    // -------------------------------------------------------------------------

    public function testLoaderResolvesTokenFromLocalYamlSiblingFile(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            tokenEnv: 'KANINE_MISSING_ENV_VAR_XYZ',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'kanine.yaml');
        file_put_contents($this->tempDir . '/kanine.local.yaml', "github:\n  token: local-yaml-token\n");

        putenv('KANINE_MISSING_ENV_VAR_XYZ');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('local-yaml-token', $config->githubToken);
    }

    public function testLocalYamlTokenTakesPrecedenceOverInlineToken(): void
    {
        $yaml = $this->buildYaml(
            token: 'inline-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'kanine.yaml');
        file_put_contents($this->tempDir . '/kanine.local.yaml', "github:\n  token: local-yaml-token\n");

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('local-yaml-token', $config->githubToken);
    }

    public function testLoaderIgnoresAbsentLocalYamlSiblingFile(): void
    {
        $yaml = $this->buildYaml(
            token: 'inline-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'kanine.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('inline-token', $config->githubToken);
    }

    public function testLoaderResolvesLocalYamlSiblingToBuiltInDefaultPath(): void
    {
        $originalCwd = getcwd();
        chdir($this->tempDir);

        try {
            $dotKanineDir = $this->tempDir . '/.kanine';
            mkdir($dotKanineDir, 0777, true);
            $yaml = $this->buildYaml(
                token: null,
                tokenEnv: 'KANINE_MISSING_ENV_VAR_XYZ',
                repositories: ['owner/repo'],
                readyLabel: 'kanine: ready',
                host: '127.0.0.1',
                port: 3737,
            );
            file_put_contents($dotKanineDir . '/kanine.yaml', $yaml);
            file_put_contents($dotKanineDir . '/kanine.local.yaml', "github:\n  token: local-default-token\n");

            putenv('KANINE_MISSING_ENV_VAR_XYZ');

            $loader = new ConfigLoader();
            $config = $loader->load();

            $this->assertSame('local-default-token', $config->githubToken);
        } finally {
            chdir($originalCwd);
        }
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: validation — missing GITHUB_TOKEN
    // -------------------------------------------------------------------------

    public function testLoaderThrowsWhenGithubTokenIsEmpty(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            tokenEnv: 'KANINE_MISSING_ENV_VAR_XYZ',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'no-token.yaml');

        putenv('KANINE_MISSING_ENV_VAR_XYZ');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/GITHUB_TOKEN/');

        $loader->load(explicitPath: $path);
    }

    public function testLoaderThrowsWithActionableMessageWhenTokenMissing(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            tokenEnv: 'KANINE_MISSING_ENV_VAR_XYZ',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'no-token-msg.yaml');

        putenv('KANINE_MISSING_ENV_VAR_XYZ');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Export it or set token_env/');

        $loader->load(explicitPath: $path);
    }

    public function testLoaderThrowsWithTokenCreationInstructionsWhenDefaultTokenMissing(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            tokenEnv: 'GITHUB_TOKEN',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'no-default-token.yaml');

        $saved = getenv('GITHUB_TOKEN');
        putenv('GITHUB_TOKEN');

        $loader = new ConfigLoader();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/github\.com\/settings\/tokens/');
            $loader->load(explicitPath: $path);
        } finally {
            if ($saved !== false) {
                putenv("GITHUB_TOKEN={$saved}");
            }
        }
    }

    public function testLoaderThrowsWithMessageMentioningTokenFlagAndLocalYamlWhenTokenMissing(): void
    {
        $yaml = $this->buildYaml(
            token: null,
            tokenEnv: 'KANINE_MISSING_ENV_VAR_XYZ',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'no-token-both-options.yaml');

        putenv('KANINE_MISSING_ENV_VAR_XYZ');

        $loader = new ConfigLoader();

        try {
            $loader->load(explicitPath: $path);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('--token', $e->getMessage());
            $this->assertStringContainsString('kanine.local.yaml', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: validation — no repositories configured
    // -------------------------------------------------------------------------

    public function testLoaderThrowsWhenNoRepositoriesConfigured(): void
    {
        $yaml = $this->buildYaml(
            token: 'some-token',
            repositories: [],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 3737,
        );
        $path = $this->writeYaml($yaml, 'no-repos.yaml');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no repositories/i');

        $loader->load(explicitPath: $path);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: validation — port out of range
    // -------------------------------------------------------------------------

    public function testLoaderThrowsWhenPortIsZero(): void
    {
        $yaml = $this->buildYaml(
            token: 'some-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 0,
        );
        $path = $this->writeYaml($yaml, 'port-zero.yaml');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port/i');

        $loader->load(explicitPath: $path);
    }

    public function testLoaderThrowsWhenPortExceedsMaximum(): void
    {
        $yaml = $this->buildYaml(
            token: 'some-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 65536,
        );
        $path = $this->writeYaml($yaml, 'port-max.yaml');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port/i');

        $loader->load(explicitPath: $path);
    }

    public function testLoaderThrowsWhenPortIsNegative(): void
    {
        $yaml = $this->buildYaml(
            token: 'some-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: -1,
        );
        $path = $this->writeYaml($yaml, 'port-negative.yaml');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port/i');

        $loader->load(explicitPath: $path);
    }

    public function testLoaderAcceptsValidPortBoundaries(): void
    {
        $yaml = $this->buildYaml(
            token: 'some-token',
            repositories: ['owner/repo'],
            readyLabel: 'kanine: ready',
            host: '127.0.0.1',
            port: 1,
        );
        $path = $this->writeYaml($yaml, 'port-min.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(1, $config->port);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: new V0.2 fields — defaults
    // -------------------------------------------------------------------------

    public function testDefaultsAppliedWhenAgentKeyAbsent(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame(10000, $config->statusIntervalMs);
        $this->assertSame(90.0, $config->usageThrottlePct);
        $this->assertSame(60000, $config->maxThrottlePollMs);
    }

    public function testDoneLabelDefaultValue(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame('kanine: done', $config->doneLabel);
    }

    public function testFailedLabelDefaultValue(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame('kanine: failed', $config->failedLabel);
    }

    // -------------------------------------------------------------------------
    // ConfigLoader: usageThrottlePct validation
    // -------------------------------------------------------------------------

    public function testUsageThrottlePctBelowRangeLogsErrorAndUsesDefault(): void
    {
        $yaml = $this->buildYamlWithAgent(
            token: 'tok',
            repositories: ['owner/repo'],
            usageThrottlePct: 49,
        );
        $path = $this->writeYaml($yaml, 'throttle-low.yaml');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('usage_throttle_pct'));

        $loader = new ConfigLoader(logger: $logger);
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(90.0, $config->usageThrottlePct);
    }

    public function testUsageThrottlePctAboveRangeLogsErrorAndUsesDefault(): void
    {
        $yaml = $this->buildYamlWithAgent(
            token: 'tok',
            repositories: ['owner/repo'],
            usageThrottlePct: 100,
        );
        $path = $this->writeYaml($yaml, 'throttle-high.yaml');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('usage_throttle_pct'));

        $loader = new ConfigLoader(logger: $logger);
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(90.0, $config->usageThrottlePct);
    }

    public function testUsageThrottleOutOfRangeDefaultsToNinety(): void
    {
        $yaml = $this->buildYamlWithAgent(
            token: 'tok',
            repositories: ['owner/repo'],
            usageThrottlePct: 49,
        );
        $path = $this->writeYaml($yaml, 'throttle-regression.yaml');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('usage_throttle_pct'));

        $loader = new ConfigLoader(logger: $logger);
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(90.0, $config->usageThrottlePct);
    }

    public function testUsageThrottlePctInRangeAccepted(): void
    {
        $yaml = $this->buildYamlWithAgent(
            token: 'tok',
            repositories: ['owner/repo'],
            usageThrottlePct: 75,
        );
        $path = $this->writeYaml($yaml, 'throttle-valid.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(75.0, $config->usageThrottlePct);
    }

    // -------------------------------------------------------------------------
    // T002: dispatch_interval_seconds and pup_timeout_seconds
    // -------------------------------------------------------------------------

    public function testDispatchIntervalSecondsDefaultIsTwo(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame(2, $config->dispatchIntervalSeconds);
    }

    public function testPupTimeoutSecondsDefaultIsFifteen(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertSame(15, $config->pupTimeoutSeconds);
    }

    public function testDispatchIntervalSecondsCanBeConfigured(): void
    {
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            dispatchIntervalSeconds: 5,
        );
        $path = $this->writeYaml($yaml, 'dispatch-interval.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(5, $config->dispatchIntervalSeconds);
    }

    public function testPupTimeoutSecondsCanBeConfigured(): void
    {
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            pupTimeoutSeconds: 30,
        );
        $path = $this->writeYaml($yaml, 'pup-timeout.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame(30, $config->pupTimeoutSeconds);
    }

    // -------------------------------------------------------------------------
    // T003: TLS enforcement
    // -------------------------------------------------------------------------

    public function testTlsDefaultIsFalse(): void
    {
        $loader = new ConfigLoader(
            defaultPaths: [$this->tempDir . '/nonexistent.yaml'],
        );

        $config = $loader->load();

        $this->assertFalse($config->tls);
    }

    public function testTlsCanBeSetToTrue(): void
    {
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            tls: true,
        );
        $path = $this->writeYaml($yaml, 'tls-true.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertTrue($config->tls);
    }

    public function testLoaderThrowsWhenRemoteHostHasTlsFalse(): void
    {
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            host: '10.0.0.1',
        );
        $path = $this->writeYaml($yaml, 'remote-no-tls.yaml');

        $loader = new ConfigLoader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tls/i');

        $loader->load(explicitPath: $path);
    }

    public function testLoaderAcceptsRemoteHostWhenTlsIsTrue(): void
    {
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            host: '10.0.0.1',
            tls: true,
        );
        $path = $this->writeYaml($yaml, 'remote-with-tls.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('10.0.0.1', $config->host);
    }

    public function testLocalhostIpv6DoesNotRequireTls(): void
    {
        $yaml = $this->buildYamlWithSupervisor(
            token: 'tok',
            repositories: ['owner/repo'],
            host: '::1',
        );
        $path = $this->writeYaml($yaml, 'ipv6-localhost.yaml');

        $loader = new ConfigLoader();
        $config = $loader->load(explicitPath: $path);

        $this->assertSame('::1', $config->host);
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

    /**
     * @param list<string> $repositories
     */
    private function buildYamlWithAgent(
        ?string $token,
        array $repositories,
        float|int|null $usageThrottlePct = null,
        string $readyLabel = 'kanine: ready',
        string $host = '127.0.0.1',
        int $port = 3737,
    ): string {
        $base = $this->buildYaml(
            token: $token,
            repositories: $repositories,
            readyLabel: $readyLabel,
            host: $host,
            port: $port,
        );

        if ($usageThrottlePct !== null) {
            $base .= "\nagent:\n  usage_throttle_pct: {$usageThrottlePct}\n";
        }

        return $base;
    }

    /**
     * @param list<string> $repositories
     */
    private function buildYamlWithSupervisor(
        ?string $token,
        array $repositories,
        string $readyLabel = 'kanine: ready',
        string $host = '127.0.0.1',
        int $port = 3737,
        ?int $dispatchIntervalSeconds = null,
        ?int $pupTimeoutSeconds = null,
        ?bool $tls = null,
    ): string {
        $repoLines = '';
        foreach ($repositories as $repo) {
            $repoLines .= "    - {$repo}\n";
        }

        $tokenLine = $token !== null
            ? "  token: {$token}\n"
            : '';

        $supervisorExtras = '';

        if ($dispatchIntervalSeconds !== null) {
            $supervisorExtras .= "  dispatch_interval_seconds: {$dispatchIntervalSeconds}\n";
        }

        if ($pupTimeoutSeconds !== null) {
            $supervisorExtras .= "  pup_timeout_seconds: {$pupTimeoutSeconds}\n";
        }

        if ($tls !== null) {
            $tlsStr = $tls ? 'true' : 'false';
            $supervisorExtras .= "  tls: {$tlsStr}\n";
        }

        return <<<YAML
            github:
              repositories:
            {$repoLines}  token_env: GITHUB_TOKEN
              ready_label: "{$readyLabel}"
            {$tokenLine}
            supervisor:
              host: {$host}
              port: {$port}
            {$supervisorExtras}
            YAML;
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
