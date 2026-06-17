<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Integration;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Config\ConfigLoader;
use ScottKeckWarren\Kanine\Config\Configuration;
use ScottKeckWarren\Kanine\Domain\Task;
use ScottKeckWarren\Kanine\Domain\TaskState;
use ScottKeckWarren\Kanine\Logger\LoggerFactory;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;
use ScottKeckWarren\Kanine\Supervisor\TaskQueue;

/**
 * Integration smoke tests that can run without live GitHub or claude --headless.
 *
 * A true E2E test (real GitHub issue + real claude --headless) lives in
 * bin/smoke-test.sh and must be run manually in an environment with valid
 * credentials and a test repository.
 */
final class SmokeTest extends TestCase
{
    private string $tempDir;

    // -------------------------------------------------------------------------
    // Config loading pipeline
    // -------------------------------------------------------------------------

    public function testConfigLoaderProducesValidConfigurationFromYamlFile(): void
    {
        $yaml = $this->buildValidYaml();
        $path = $this->writeYaml($yaml, 'smoke.yaml');

        $config = (new ConfigLoader())->load(explicitPath: $path);

        $this->assertInstanceOf(Configuration::class, $config);
        $this->assertSame('smoke-token', $config->githubToken);
        $this->assertSame(['owner/smoke-repo'], $config->repositories);
        $this->assertSame(3737, $config->port);
        $this->assertSame('127.0.0.1', $config->host);
    }

    public function testMissingGithubTokenCausesConfigLoadToFail(): void
    {
        $yaml = <<<YAML
            github:
              repositories:
                - owner/repo
              token_env: KANINE_SMOKE_MISSING_TOKEN_XYZ
            supervisor:
              host: 127.0.0.1
              port: 3737
            YAML;

        $path = $this->writeYaml($yaml, 'no-token.yaml');

        putenv('KANINE_SMOKE_MISSING_TOKEN_XYZ');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/GITHUB_TOKEN/');

        (new ConfigLoader())->load(explicitPath: $path);
    }

    public function testNoRepositoriesCausesConfigLoadToFail(): void
    {
        $yaml = <<<YAML
            github:
              repositories: []
              token: some-token
            supervisor:
              host: 127.0.0.1
              port: 3737
            YAML;

        $path = $this->writeYaml($yaml, 'no-repos.yaml');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no repositories/i');

        (new ConfigLoader())->load(explicitPath: $path);
    }

    public function testInvalidPortCausesConfigLoadToFail(): void
    {
        $yaml = <<<YAML
            github:
              repositories:
                - owner/repo
              token: some-token
            supervisor:
              host: 127.0.0.1
              port: 99999
            YAML;

        $path = $this->writeYaml($yaml, 'bad-port.yaml');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/port/i');

        (new ConfigLoader())->load(explicitPath: $path);
    }

    // -------------------------------------------------------------------------
    // Logger output format
    // -------------------------------------------------------------------------

    public function testLoggerProducesCorrectFormat(): void
    {
        $logFile = $this->tempDir . '/smoke.log';
        $logger  = (new LoggerFactory())->create(logFile: $logFile);

        $logger->info('smoke test message');

        $files = glob($this->tempDir . '/smoke*.log');
        $this->assertNotEmpty($files);

        $contents = (string) file_get_contents((string) $files[0]);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] kanine\.INFO: smoke test message$/m',
            $contents,
        );
    }

    public function testLoggerIsMonologInstance(): void
    {
        $logFile = $this->tempDir . '/smoke.log';
        $logger  = (new LoggerFactory())->create(logFile: $logFile);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('kanine', $logger->getName());
    }

    // -------------------------------------------------------------------------
    // TaskQueue + PupRegistry wiring
    // -------------------------------------------------------------------------

    public function testTaskQueueAcceptsAndDispatchesTasks(): void
    {
        $queue = new TaskQueue();
        $task  = new Task(
            id: 'smoke-task-1',
            issueNumber: 42,
            repo: 'owner/smoke-repo',
            title: 'Smoke issue',
            body: 'Smoke body',
            labels: [],
            state: TaskState::Queued,
        );

        $queue->enqueue($task);
        $dequeued = $queue->dequeue();

        $this->assertSame($task->id, $dequeued?->id);
        $this->assertNull($queue->dequeue());
    }

    public function testPupRegistryRegistersAndValidatesToken(): void
    {
        $registry = new PupRegistry();
        $token    = $registry->register(pupId: 'smoke-pup-1', hostname: 'smoke-host');

        $this->assertTrue($registry->validate(pupId: 'smoke-pup-1', token: $token));
        $this->assertNotNull($registry->find('smoke-pup-1'));
    }

    public function testPupRegistryRejectsWrongToken(): void
    {
        $registry = new PupRegistry();
        $registry->register(pupId: 'smoke-pup-2', hostname: 'smoke-host');

        $this->assertFalse($registry->validate(pupId: 'smoke-pup-2', token: 'wrong-token'));
    }

    public function testTaskIsAssignedToPupViaRegistry(): void
    {
        $queue    = new TaskQueue();
        $registry = new PupRegistry();

        $task = new Task(
            id: 'smoke-assign-1',
            issueNumber: 7,
            repo: 'owner/repo',
            title: 'Assign smoke',
            body: 'body',
            labels: [],
            state: TaskState::Queued,
        );
        $queue->enqueue($task);

        $registry->register(pupId: 'smoke-pup-3', hostname: 'host');
        $dequeued = $queue->dequeue();

        $this->assertNotNull($dequeued);
        $registry->assign(pupId: 'smoke-pup-3', taskId: $dequeued->id);

        $pup = $registry->find('smoke-pup-3');
        $this->assertSame($dequeued->id, $pup?->assignedTaskId);
    }

    // -------------------------------------------------------------------------
    // Third-party dependency availability
    // -------------------------------------------------------------------------

    public function testGitHubApiClientCanBeInstantiated(): void
    {
        $client = new \Github\Client();

        $this->assertInstanceOf(\Github\Client::class, $client);
    }

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kanine-smoke-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildValidYaml(): string
    {
        return <<<YAML
            github:
              repositories:
                - owner/smoke-repo
              token: smoke-token
              ready_label: "kanine: ready"
            supervisor:
              host: 127.0.0.1
              port: 3737
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
