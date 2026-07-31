<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Config;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the Kanine configuration and GitHub token.
 *
 * Token resolution order (highest priority first):
 *   1. An explicit token passed programmatically (the `--token` CLI flag).
 *   2. `github.token` in a `kanine.local.yaml` file living alongside whichever
 *      main config file was loaded. This file is optional, gitignored, and
 *      SHOULD be created with `chmod 0600` permissions since it holds a secret.
 *   3. Inline `github.token` in the main config file, or the environment
 *      variable named by `github.token_env` (default `GITHUB_TOKEN`).
 */
final class ConfigLoader implements ConfigLoaderInterface
{
    private const DEFAULT_HOST               = '127.0.0.1';
    private const DEFAULT_PORT               = 3737;
    private const DEFAULT_READY_LABEL        = 'kanine: ready';
    private const DEFAULT_DONE_LABEL            = 'kanine: done';
    private const DEFAULT_FAILED_LABEL          = 'kanine: failed';
    private const DEFAULT_ARCHITECT_LABEL       = 'architect';
    private const DEFAULT_HUMAN_FEEDBACK_LABEL  = 'human feedback needed';
    private const DEFAULT_TOKEN_ENV          = 'GITHUB_TOKEN';
    private const DEFAULT_STATUS_INTERVAL_MS   = 10000;
    private const DEFAULT_USAGE_THROTTLE_PCT   = 90.0;
    private const DEFAULT_MAX_THROTTLE_POLL_MS = 60000;
    private const DEFAULT_DISPATCH_INTERVAL_S  = 2;
    private const DEFAULT_PUP_TIMEOUT_S        = 15;

    /** @var list<string> */
    private readonly array $defaultPaths;

    private readonly LoggerInterface $logger;

    /**
     * @param list<string>|null $defaultPaths Ordered list of candidate config paths; first existing file wins.
     *                                         Defaults to the standard XDG/project locations.
     */
    public function __construct(?array $defaultPaths = null, ?LoggerInterface $logger = null)
    {
        $this->defaultPaths = $defaultPaths ?? [
            getcwd() . '/.kanine/kanine.yaml',
            ($_SERVER['HOME'] ?? '') . '/.config/kanine/kanine.yaml',
        ];
        $this->logger = $logger ?? new NullLogger();
    }

    public function load(?string $explicitPath = null, ?string $tokenOverride = null): Configuration
    {
        if ($explicitPath !== null) {
            return $this->loadFromPath($explicitPath, $tokenOverride);
        }

        foreach ($this->defaultPaths as $candidate) {
            if (file_exists($candidate)) {
                return $this->loadFromPath($candidate, $tokenOverride);
            }
        }

        return $this->buildDefaults($tokenOverride);
    }

    private function loadFromPath(string $path, ?string $tokenOverride): Configuration
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(
                "Config file not found: {$path}"
            );
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return $this->buildFromData($data, $tokenOverride, $this->readLocalToken($path));
    }

    /**
     * Reads `github.token` from a `kanine.local.yaml` file sitting next to the given main
     * config file path. Returns null if the sibling file doesn't exist or has no token set.
     */
    private function readLocalToken(string $mainConfigPath): ?string
    {
        $localPath = dirname($mainConfigPath) . '/kanine.local.yaml';

        if (!file_exists($localPath)) {
            return null;
        }

        /** @var array<string, mixed> $localData */
        $localData = Yaml::parseFile($localPath);

        /** @var array<string, mixed> $github */
        $github = $localData['github'] ?? [];

        return isset($github['token']) ? (string) $github['token'] : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildFromData(array $data, ?string $tokenOverride, ?string $localToken): Configuration
    {
        /** @var array<string, mixed> $github */
        $github = $data['github'] ?? [];

        /** @var array<string, mixed> $supervisor */
        $supervisor = $data['supervisor'] ?? [];

        /** @var array<string, mixed> $agent */
        $agent = $data['agent'] ?? [];

        /** @var array<string, mixed> $labels */
        $labels = $github['labels'] ?? [];

        $tokenEnv = (string) ($github['token_env'] ?? self::DEFAULT_TOKEN_ENV);
        $inlineToken = isset($github['token'])
            ? (string) $github['token']
            : (string) (getenv($tokenEnv) ?: '');
        $token = $this->resolveToken($tokenOverride, $localToken, $inlineToken);

        /** @var list<string> $repositories */
        $repositories = array_values(array_map(
            'strval',
            (array) ($github['repositories'] ?? [])
        ));

        $port = (int) ($supervisor['port'] ?? self::DEFAULT_PORT);

        $usageThrottlePct = $this->resolveUsageThrottlePct($agent);

        $config = new Configuration(
            host: (string) ($supervisor['host'] ?? self::DEFAULT_HOST),
            port: $port,
            githubToken: $token,
            repositories: $repositories,
            readyLabel: (string) ($github['ready_label'] ?? self::DEFAULT_READY_LABEL),
            logFile: isset($data['log_file']) ? (string) $data['log_file'] : null,
            statusIntervalMs: (int) ($agent['status_interval_ms'] ?? self::DEFAULT_STATUS_INTERVAL_MS),
            usageThrottlePct: $usageThrottlePct,
            maxThrottlePollMs: (int) ($agent['max_throttle_poll_ms'] ?? self::DEFAULT_MAX_THROTTLE_POLL_MS),
            doneLabel: (string) ($labels['done'] ?? self::DEFAULT_DONE_LABEL),
            failedLabel: (string) ($labels['failed'] ?? self::DEFAULT_FAILED_LABEL),
            architectLabel: (string) ($labels['architect'] ?? self::DEFAULT_ARCHITECT_LABEL),
            humanFeedbackLabel: (string) ($labels['human_feedback'] ?? self::DEFAULT_HUMAN_FEEDBACK_LABEL),
            dispatchIntervalSeconds: (int) (
                $supervisor['dispatch_interval_seconds'] ?? self::DEFAULT_DISPATCH_INTERVAL_S
            ),
            pupTimeoutSeconds: (int) ($supervisor['pup_timeout_seconds'] ?? self::DEFAULT_PUP_TIMEOUT_S),
            tls: (bool) ($supervisor['tls'] ?? false),
        );

        $this->validate($config, $tokenEnv);

        return $config;
    }

    private function resolveToken(?string $tokenOverride, ?string $localToken, string $inlineToken): string
    {
        if ($tokenOverride !== null && $tokenOverride !== '') {
            return $tokenOverride;
        }

        if ($localToken !== null && $localToken !== '') {
            return $localToken;
        }

        return $inlineToken;
    }

    /**
     * @param array<string, mixed> $agent
     */
    private function resolveUsageThrottlePct(array $agent): float
    {
        if (!isset($agent['usage_throttle_pct'])) {
            return self::DEFAULT_USAGE_THROTTLE_PCT;
        }

        $value = (float) $agent['usage_throttle_pct'];

        if ($value < 50.0 || $value > 99.0) {
            $this->logger->error(
                "Config error: usage_throttle_pct must be between 50 and 99 (got {$value}). Using default 90.0."
            );

            return self::DEFAULT_USAGE_THROTTLE_PCT;
        }

        return $value;
    }

    private function validate(Configuration $config, string $tokenEnv): void
    {
        if ($config->githubToken === '') {
            $alternativeOptions = "\n\nAlternatively, you can supply the token via:\n"
                . "  - The --token CLI flag: kanine serve --token ghp_yourtoken\n"
                . "  - A github.token entry in .kanine/kanine.local.yaml (gitignored, chmod 0600)";

            if ($tokenEnv === self::DEFAULT_TOKEN_ENV) {
                throw new \InvalidArgumentException(
                    "ERROR: GITHUB_TOKEN env var not set.\n\n"
                    . "Create a GitHub Personal Access Token:\n"
                    . "  https://github.com/settings/tokens/new\n"
                    . "  Required scopes: repo (private repos) or public_repo (public repos only)\n\n"
                    . "Then export it in your shell:\n"
                    . "  export GITHUB_TOKEN=ghp_yourtoken\n\n"
                    . "Or add it permanently to ~/.zshrc (or ~/.bashrc):\n"
                    . "  export GITHUB_TOKEN=ghp_yourtoken"
                    . $alternativeOptions
                );
            }

            throw new \InvalidArgumentException(
                "ERROR: GITHUB_TOKEN env var not set. Export it or set token_env in kanine.yaml."
                . " (token_env resolved to: {$tokenEnv})"
                . $alternativeOptions
            );
        }

        if ($config->repositories === []) {
            throw new \InvalidArgumentException(
                'ERROR: No repositories configured. Add at least one entry under github.repositories in kanine.yaml.'
            );
        }

        if ($config->port < 1 || $config->port > 65535) {
            throw new \InvalidArgumentException(
                "ERROR: Invalid port {$config->port}. Port must be in range 1–65535."
            );
        }

        if ($config->host !== '127.0.0.1' && $config->host !== '::1' && $config->tls === false) {
            throw new \InvalidArgumentException(
                'ERROR: supervisor.tls must be true when host is not 127.0.0.1 or ::1.'
                . ' Set supervisor.tls: true in kanine.yaml or bind to localhost.'
            );
        }
    }

    private function buildDefaults(?string $tokenOverride): Configuration
    {
        $inlineToken = (string) (getenv(self::DEFAULT_TOKEN_ENV) ?: '');

        return new Configuration(
            host: self::DEFAULT_HOST,
            port: self::DEFAULT_PORT,
            githubToken: $this->resolveToken($tokenOverride, null, $inlineToken),
            repositories: [],
            readyLabel: self::DEFAULT_READY_LABEL,
            logFile: null,
            statusIntervalMs: self::DEFAULT_STATUS_INTERVAL_MS,
            usageThrottlePct: self::DEFAULT_USAGE_THROTTLE_PCT,
            maxThrottlePollMs: self::DEFAULT_MAX_THROTTLE_POLL_MS,
            doneLabel: self::DEFAULT_DONE_LABEL,
            failedLabel: self::DEFAULT_FAILED_LABEL,
            architectLabel: self::DEFAULT_ARCHITECT_LABEL,
            humanFeedbackLabel: self::DEFAULT_HUMAN_FEEDBACK_LABEL,
            dispatchIntervalSeconds: self::DEFAULT_DISPATCH_INTERVAL_S,
            pupTimeoutSeconds: self::DEFAULT_PUP_TIMEOUT_S,
            tls: false,
        );
    }
}
