<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Console;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Config\ConfigInitializerInterface;
use ScottKeckWarren\Kanine\Console\Command\InitCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class InitCommandTest extends TestCase
{
    public function testInitCommandNameIsInit(): void
    {
        $initializer = $this->createMock(ConfigInitializerInterface::class);

        $command = new InitCommand($initializer);

        $this->assertSame('init', $command->getName());
    }

    public function testInitCommandDelegatesToConfigInitializerRun(): void
    {
        $initializer = $this->createMock(ConfigInitializerInterface::class);
        $initializer->expects($this->once())->method('run');

        $command = new InitCommand($initializer);
        $tester  = new CommandTester($command);
        $tester->execute([]);
    }

    public function testInitCommandExitsZeroOnSuccess(): void
    {
        $initializer = $this->createMock(ConfigInitializerInterface::class);
        $initializer->method('run');

        $command = new InitCommand($initializer);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testInitCommandExitsNonzeroWhenInitializerThrows(): void
    {
        $initializer = $this->createMock(ConfigInitializerInterface::class);
        $initializer->method('run')->willThrowException(new \RuntimeException('Aborted'));

        $command = new InitCommand($initializer);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
    }
}
