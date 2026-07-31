<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Config;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Config\ConfigInitializer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConfigInitializerTest extends TestCase
{
    private string $tempDir;

    // -------------------------------------------------------------------------
    // configExists()
    // -------------------------------------------------------------------------

    public function testConfigExistsReturnsFalseWhenNoDotKanineYaml(): void
    {
        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $this->assertFalse($initializer->configExists());
    }

    public function testConfigExistsReturnsTrueWhenDotKanineYamlPresent(): void
    {
        $dotKanineDir = $this->tempDir . '/.kanine';
        mkdir($dotKanineDir, 0777, true);
        file_put_contents($dotKanineDir . '/kanine.yaml', 'github: {}');

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $this->assertTrue($initializer->configExists());
    }

    // -------------------------------------------------------------------------
    // write()
    // -------------------------------------------------------------------------

    public function testWriteCreatesDotKanineDirectory(): void
    {
        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $initializer->write([
            'token_env'    => 'GITHUB_TOKEN',
            'repositories' => ['acme/api'],
        ]);

        $this->assertDirectoryExists($this->tempDir . '/.kanine');
    }

    public function testWriteCreatesKanineYamlFile(): void
    {
        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $initializer->write([
            'token_env'    => 'GITHUB_TOKEN',
            'repositories' => ['acme/api'],
        ]);

        $this->assertFileExists($this->tempDir . '/.kanine/kanine.yaml');
    }

    public function testWriteIncludesTokenEnvInYaml(): void
    {
        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $initializer->write([
            'token_env'    => 'MY_TOKEN',
            'repositories' => ['acme/api'],
        ]);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringContainsString('MY_TOKEN', $content);
    }

    public function testWriteIncludesRepositoriesInYaml(): void
    {
        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $initializer->write([
            'token_env'    => 'GITHUB_TOKEN',
            'repositories' => ['acme/api', 'acme/web'],
        ]);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringContainsString('acme/api', $content);
        $this->assertStringContainsString('acme/web', $content);
    }

    public function testWriteIncludesCommentedDefaultsInYaml(): void
    {
        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $initializer->write([
            'token_env'    => 'GITHUB_TOKEN',
            'repositories' => ['acme/api'],
        ]);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringContainsString('# log_file:', $content);
    }

    // -------------------------------------------------------------------------
    // run() — full wizard flow
    // -------------------------------------------------------------------------

    public function testRunPromptsForTokenEnvAndRepositories(): void
    {
        $input = $this->makeInput("MY_TOKEN\nacme/api\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $this->assertFileExists($this->tempDir . '/.kanine/kanine.yaml');
    }

    public function testRunUsesDefaultTokenEnvWhenBlankInput(): void
    {
        $input  = $this->makeInput("\nacme/api\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringContainsString('GITHUB_TOKEN', $content);
    }

    public function testRunRejectsInvalidRepoFormat(): void
    {
        // "notavalidrepo" doesn't match owner/repo; "acme/api" does
        $input  = $this->makeInput("\nnotavalidrepo\nacme/api\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringNotContainsString('notavalidrepo', $content);
        $this->assertStringContainsString('acme/api', $content);
    }

    public function testRunRequiresAtLeastOneRepository(): void
    {
        // Blank line immediately, then a valid repo, then blank to finish
        $input  = $this->makeInput("\n\nacme/api\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringContainsString('acme/api', $content);
    }

    public function testRunPrintsCompletionMessage(): void
    {
        $input  = $this->makeInput("\nacme/api\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $this->assertStringContainsString('.kanine/kanine.yaml', $output->fetch());
    }

    // -------------------------------------------------------------------------
    // run() — optional local token
    // -------------------------------------------------------------------------

    public function testRunDoesNotCreateLocalYamlWhenTokenLeftBlank(): void
    {
        $input  = $this->makeInput("\nacme/api\n\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $this->assertFileDoesNotExist($this->tempDir . '/.kanine/kanine.local.yaml');
    }

    public function testRunWritesLocalYamlWhenTokenProvided(): void
    {
        $input  = $this->makeInput("\nacme/api\n\nghp_supersecret\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $this->assertFileExists($this->tempDir . '/.kanine/kanine.local.yaml');
        $content = file_get_contents($this->tempDir . '/.kanine/kanine.local.yaml');
        $this->assertStringContainsString('ghp_supersecret', $content);
    }

    public function testRunDoesNotWriteTokenIntoMainConfigFile(): void
    {
        $input  = $this->makeInput("\nacme/api\n\nghp_supersecret\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $content = file_get_contents($this->tempDir . '/.kanine/kanine.yaml');
        $this->assertStringNotContainsString('ghp_supersecret', $content);
    }

    public function testRunSetsLocalYamlPermissionsToOwnerReadWriteOnly(): void
    {
        $input  = $this->makeInput("\nacme/api\n\nghp_supersecret\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $permissions = fileperms($this->tempDir . '/.kanine/kanine.local.yaml') & 0777;
        $this->assertSame(0600, $permissions);
    }

    public function testRunAbortsWhenUserDeclinesOverwrite(): void
    {
        // Pre-create the config file
        $dotKanineDir = $this->tempDir . '/.kanine';
        mkdir($dotKanineDir, 0777, true);
        file_put_contents($dotKanineDir . '/kanine.yaml', 'github: {}');

        // User answers "n" to overwrite prompt
        $input  = $this->makeInput("n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Aborted');

        $initializer->run($input, $output);
    }

    public function testRunProceedsWhenUserConfirmsOverwrite(): void
    {
        // Pre-create the config file
        $dotKanineDir = $this->tempDir . '/.kanine';
        mkdir($dotKanineDir, 0777, true);
        file_put_contents($dotKanineDir . '/kanine.yaml', 'github: {}');

        // User answers "y" to overwrite, then provides token/repos
        $input  = $this->makeInput("y\n\nacme/api\n\n");
        $output = new BufferedOutput();

        $initializer = new ConfigInitializer(baseDir: $this->tempDir);
        $initializer->run($input, $output);

        $content = file_get_contents($dotKanineDir . '/kanine.yaml');
        $this->assertStringContainsString('acme/api', $content);
    }

    // -------------------------------------------------------------------------
    // PHPUnit lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/kanine-init-test-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeInput(string $userInput): ArrayInput
    {
        // ArrayInput reads from the command line arguments, not stdin.
        // We simulate interactive stdin via a stream wrapper.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $userInput);
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $input->setInteractive(true);

        return $input;
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
