<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use ScottKeckWarren\Kanine\Domain\Pup;
use ScottKeckWarren\Kanine\Domain\PupStatus;
use ScottKeckWarren\Kanine\Supervisor\PupRegistry;

final class PupRegistryTest extends TestCase
{
    private PupRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PupRegistry();
    }

    public function test_it_returns_a_token_when_registering_a_pup(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertNotEmpty($token);
    }

    public function test_it_returns_a_string_token(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertIsString($token);
    }

    public function test_it_finds_a_registered_pup_by_id(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertInstanceOf(Pup::class, $pup);
        $this->assertSame('pup-001', $pup->id);
        $this->assertSame('worker-01.local', $pup->hostname);
    }

    public function test_it_stores_pup_as_idle_on_registration(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    public function test_it_stores_pup_with_no_assigned_task_on_registration(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertNotNull($pup);
        $this->assertNull($pup->assignedTaskId);
    }

    public function test_it_stores_the_token_on_the_pup(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertNotNull($pup);
        $this->assertSame($token, $pup->token);
    }

    public function test_find_returns_null_for_unknown_pup_id(): void
    {
        $pup = $this->registry->find('unknown-pup');

        $this->assertNull($pup);
    }

    public function test_validate_returns_true_for_correct_token(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertTrue($this->registry->validate(pupId: 'pup-001', token: $token));
    }

    public function test_validate_returns_false_for_wrong_token(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertFalse($this->registry->validate(pupId: 'pup-001', token: 'wrong-token'));
    }

    public function test_validate_returns_false_for_unknown_pup_id(): void
    {
        $this->assertFalse($this->registry->validate(pupId: 'unknown-pup', token: 'any-token'));
    }

    public function test_two_registrations_produce_different_tokens(): void
    {
        $tokenA = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');
        $tokenB = $this->registry->register(pupId: 'pup-002', hostname: 'worker-02.local');

        $this->assertNotSame($tokenA, $tokenB);
    }

    public function test_update_status_changes_the_pup_status(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Working, $pup->status);
    }

    public function test_update_status_back_to_idle_is_reflected_on_find(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');
        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Idle);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    public function test_update_status_preserves_token(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame($token, $pup->token);
    }

    public function test_update_status_preserves_hostname(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame('worker-01.local', $pup->hostname);
    }
}
