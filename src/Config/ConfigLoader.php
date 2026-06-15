<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Config;

use Symfony\Component\Yaml\Yaml;

final class ConfigLoader implements ConfigLoaderInterface
{
    private const DEFAULT_HOST        = '127.0.0.1';
    private const DEFAULT_PORT        = 3737;
    private const DEFAULT_READY_LABEL = 'kanine: ready';
    private const DEFAULT_TOKEN_ENV   = 'GITHUB_TOKEN';

    /** @var list<string> */
    private readonly array $defaultPaths;

    /**
     * @param list<string>|null $defaultPaths Ordered list of candidate config paths; first existing file wins.
     *                                         Defaults to the standard XDG/project locations.
     */
    public function __construct(?array $defaultPaths = null)
    {
        $this->defaultPaths = $defaultPaths ?? [
            getcwd() . '/.kanine/kanine.yaml',
            ($_SERVER['HOME'] ?? '') . '/.config/kanine/kanine.yaml',
        ];
    }

    public function load(?string $explicitPath = null): Configuration
    {
        if ($explicitPath !== null) {
            return $this->loadFromPath($explicitPath);
        }

        foreach ($this->defaultPaths as $candidate) {
            if (file_exists($candidate)) {
                return $this->loadFromPath($candidate);
            }
        }

        return $this->buildDefaults();
    }

    private function loadFromPath(string $path): Configuration
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException(
                "Config file not found: {$path}"
            );
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($path);

        return $this->buildFromData($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildFromData(array $data): Configuration
    {
        /** @var array<string, mixed> $github */
        $github = $data['github'] ?? [];

        /** @var array<string, mixed> $supervisor */
        $supervisor = $data['supervisor'] ?? [];

        $tokenEnv = (string) ($github['token_env'] ?? self::DEFAULT_TOKEN_ENV);
        $token    = isset($github['token'])
            ? (string) $github['token']
            : (string) (getenv($tokenEnv) ?: '');

        /** @var list<string> $repositories */
        $repositories = array_values(array_map(
            'strval',
            (array) ($github['repositories'] ?? [])
        ));

        $port = (int) ($supervisor['port'] ?? self::DEFAULT_PORT);

        $config = new Configuration(
            host: (string) ($supervisor['host'] ?? self::DEFAULT_HOST),
            port: $port,
            githubToken: $token,
            repositories: $repositories,
            readyLabel: (string) ($github['ready_label'] ?? self::DEFAULT_READY_LABEL),
            logFile: isset($data['log_file']) ? (string) $data['log_file'] : null,
        );

        $this->validate($config, $tokenEnv);

        return $config;
    }

    private function validate(Configuration $config, string $tokenEnv): void
    {
        if ($config->githubToken === '') {
            throw new \InvalidArgumentException(
                "ERROR: GITHUB_TOKEN env var not set. Export it or set token_env in kanine.yaml."
                . " (token_env resolved to: {$tokenEnv})"
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
    }

    private function buildDefaults(): Configuration
    {
        return new Configuration(
            host: self::DEFAULT_HOST,
            port: self::DEFAULT_PORT,
            githubToken: (string) (getenv(self::DEFAULT_TOKEN_ENV) ?: ''),
            repositories: [],
            readyLabel: self::DEFAULT_READY_LABEL,
            logFile: null,
        );
    }
}
