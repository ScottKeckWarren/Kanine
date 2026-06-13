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

    public function testItReturnsATokenWhenRegisteringAPup(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertNotEmpty($token);
    }

    public function testItReturnsAStringToken(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertIsString($token);
    }

    public function testItFindsARegisteredPupById(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertInstanceOf(Pup::class, $pup);
        $this->assertSame('pup-001', $pup->id);
        $this->assertSame('worker-01.local', $pup->hostname);
    }

    public function testItStoresPupAsIdleOnRegistration(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    public function testItStoresPupWithNoAssignedTaskOnRegistration(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertNotNull($pup);
        $this->assertNull($pup->assignedTaskId);
    }

    public function testItStoresTheTokenOnThePup(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $pup = $this->registry->find('pup-001');

        $this->assertNotNull($pup);
        $this->assertSame($token, $pup->token);
    }

    public function testFindReturnsNullForUnknownPupId(): void
    {
        $pup = $this->registry->find('unknown-pup');

        $this->assertNull($pup);
    }

    public function testValidateReturnsTrueForCorrectToken(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertTrue($this->registry->validate(pupId: 'pup-001', token: $token));
    }

    public function testValidateReturnsFalseForWrongToken(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->assertFalse($this->registry->validate(pupId: 'pup-001', token: 'wrong-token'));
    }

    public function testValidateReturnsFalseForUnknownPupId(): void
    {
        $this->assertFalse($this->registry->validate(pupId: 'unknown-pup', token: 'any-token'));
    }

    public function testTwoRegistrationsProduceDifferentTokens(): void
    {
        $tokenA = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');
        $tokenB = $this->registry->register(pupId: 'pup-002', hostname: 'worker-02.local');

        $this->assertNotSame($tokenA, $tokenB);
    }

    public function testUpdateStatusChangesThePupStatus(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Working, $pup->status);
    }

    public function testUpdateStatusBackToIdleIsReflectedOnFind(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');
        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Idle);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame(PupStatus::Idle, $pup->status);
    }

    public function testUpdateStatusPreservesToken(): void
    {
        $token = $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame($token, $pup->token);
    }

    public function testUpdateStatusPreservesHostname(): void
    {
        $this->registry->register(pupId: 'pup-001', hostname: 'worker-01.local');

        $this->registry->updateStatus(pupId: 'pup-001', status: PupStatus::Working);

        $pup = $this->registry->find('pup-001');
        $this->assertNotNull($pup);
        $this->assertSame('worker-01.local', $pup->hostname);
    }

    protected function setUp(): void
    {
        $this->registry = new PupRegistry();
    }
}
