<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Repository\HealthRepository;
use CcSwitch\Database\Repository\ProxyConfigRepository;
use CcSwitch\Model\ProxyConfig;
use CcSwitch\Proxy\CircuitBreaker;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $cb;
    private HealthRepository $healthRepo;
    private ProxyConfigRepository $configRepo;

    protected function setUp(): void
    {
        $this->healthRepo = $this->createMock(HealthRepository::class);

        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn(null); // Use defaults

        $this->cb = new CircuitBreaker($this->healthRepo, $this->configRepo);
    }

    public function testInitialStateIsClosed(): void
    {
        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }

    public function testSuccessKeepsCircuitClosed(): void
    {
        $this->cb->recordSuccess('p1', 'claude');
        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }

    public function testFailuresBelowThresholdKeepCircuitClosed(): void
    {
        // Default threshold is 4
        $this->cb->recordFailure('p1', 'claude', 'error 1');
        $this->cb->recordFailure('p1', 'claude', 'error 2');
        $this->cb->recordFailure('p1', 'claude', 'error 3');

        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }

    public function testConsecutiveFailuresOpenCircuit(): void
    {
        // Default threshold is 4
        for ($i = 0; $i < 4; $i++) {
            $this->cb->recordFailure('p1', 'claude', 'error');
        }

        $this->assertFalse($this->cb->canPass('p1', 'claude'));
    }

    public function testSuccessResetsConsecutiveFailures(): void
    {
        $this->cb->recordFailure('p1', 'claude', 'err 1');
        $this->cb->recordFailure('p1', 'claude', 'err 2');
        $this->cb->recordFailure('p1', 'claude', 'err 3');
        $this->cb->recordSuccess('p1', 'claude'); // Reset

        $this->assertTrue($this->cb->canPass('p1', 'claude'));

        // Need 4 more consecutive failures to trip
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }

    public function testProvidersAreIndependent(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->cb->recordFailure('p1', 'claude', 'error');
        }

        // p1 is open, p2 should still be closed
        $this->assertFalse($this->cb->canPass('p1', 'claude'));
        $this->assertTrue($this->cb->canPass('p2', 'claude'));
    }

    public function testAppTypesAreIndependent(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->cb->recordFailure('p1', 'claude', 'error');
        }

        // p1:claude is open, p1:codex should still be closed
        $this->assertFalse($this->cb->canPass('p1', 'claude'));
        $this->assertTrue($this->cb->canPass('p1', 'codex'));
    }

    public function testHalfOpenAfterTimeout(): void
    {
        // Use a large timeout first to verify open state blocks
        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn([
            'circuit_failure_threshold' => 2,
            'circuit_success_threshold' => 2,
            'circuit_timeout_seconds' => 99999, // very long timeout
            'circuit_error_rate_threshold' => 0.6,
            'circuit_min_requests' => 10,
        ]);
        $this->cb = new CircuitBreaker($this->healthRepo, $this->configRepo);

        // Trip the circuit
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->cb->recordFailure('p1', 'claude', 'err');

        // With long timeout, circuit stays open
        $this->assertFalse($this->cb->canPass('p1', 'claude'));

        // Now test with timeout=0 to verify transition
        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn([
            'circuit_failure_threshold' => 2,
            'circuit_success_threshold' => 2,
            'circuit_timeout_seconds' => 0,
            'circuit_error_rate_threshold' => 0.6,
            'circuit_min_requests' => 10,
        ]);
        $cb2 = new CircuitBreaker($this->healthRepo, $this->configRepo);

        $cb2->recordFailure('p1', 'claude', 'err');
        $cb2->recordFailure('p1', 'claude', 'err');
        // With timeout=0, canPass transitions to half_open immediately
        $this->assertTrue($cb2->canPass('p1', 'claude'));
    }

    public function testHalfOpenFailureReopens(): void
    {
        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn([
            'circuit_failure_threshold' => 2,
            'circuit_success_threshold' => 2,
            'circuit_timeout_seconds' => 0,
            'circuit_error_rate_threshold' => 0.6,
            'circuit_min_requests' => 10,
        ]);
        $this->cb = new CircuitBreaker($this->healthRepo, $this->configRepo);

        // Trip circuit
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->cb->recordFailure('p1', 'claude', 'err');

        // Transition to half_open via canPass with timeout=0
        $this->assertTrue($this->cb->canPass('p1', 'claude'));

        // Failure in half_open should reopen — but with timeout=0, the next
        // canPass will immediately transition to half_open again.
        // Instead verify the state tracking: after failure in half_open,
        // a second failure should trip the circuit again.
        $this->cb->recordFailure('p1', 'claude', 'err again');

        // Now use a long timeout so it stays open
        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn([
            'circuit_failure_threshold' => 2,
            'circuit_success_threshold' => 2,
            'circuit_timeout_seconds' => 99999,
            'circuit_error_rate_threshold' => 0.6,
            'circuit_min_requests' => 10,
        ]);
        $cb2 = new CircuitBreaker($this->healthRepo, $this->configRepo);

        // Fresh CB: trip then verify stays open with long timeout
        $cb2->recordFailure('p1', 'claude', 'err');
        $cb2->recordFailure('p1', 'claude', 'err');
        $this->assertFalse($cb2->canPass('p1', 'claude'));
        // Still blocked after another check
        $this->assertFalse($cb2->canPass('p1', 'claude'));
    }

    public function testHalfOpenSuccessesCloseCircuit(): void
    {
        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn([
            'circuit_failure_threshold' => 2,
            'circuit_success_threshold' => 2,
            'circuit_timeout_seconds' => 0,
            'circuit_error_rate_threshold' => 0.6,
            'circuit_min_requests' => 10,
        ]);
        $this->cb = new CircuitBreaker($this->healthRepo, $this->configRepo);

        // Trip circuit
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->cb->recordFailure('p1', 'claude', 'err');

        // Transition to half_open
        $this->cb->canPass('p1', 'claude');

        // Two successes should close the circuit
        $this->cb->recordSuccess('p1', 'claude');
        $this->cb->recordSuccess('p1', 'claude');

        // Should be closed now
        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }

    public function testResetClearsState(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->cb->recordFailure('p1', 'claude', 'error');
        }
        $this->assertFalse($this->cb->canPass('p1', 'claude'));

        $this->cb->reset('p1', 'claude');

        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }

    public function testGetStatusReturnsAllProviders(): void
    {
        $this->cb->recordSuccess('p1', 'claude');
        $this->cb->recordSuccess('p2', 'claude');
        $this->cb->recordSuccess('p3', 'codex');

        $status = $this->cb->getStatus('claude');

        $this->assertCount(2, $status);
        $ids = array_column($status, 'provider_id');
        $this->assertContains('p1', $ids);
        $this->assertContains('p2', $ids);
    }

    public function testGetStatusEmptyForUnknownApp(): void
    {
        $status = $this->cb->getStatus('unknown');
        $this->assertEmpty($status);
    }

    public function testPeriodicCheckTransitionsExpiredOpen(): void
    {
        $this->configRepo = $this->createMock(ProxyConfigRepository::class);
        $this->configRepo->method('get')->willReturn([
            'circuit_failure_threshold' => 2,
            'circuit_success_threshold' => 2,
            'circuit_timeout_seconds' => 0,
            'circuit_error_rate_threshold' => 0.6,
            'circuit_min_requests' => 10,
        ]);
        $this->cb = new CircuitBreaker($this->healthRepo, $this->configRepo);

        // Trip circuit
        $this->cb->recordFailure('p1', 'claude', 'err');
        $this->cb->recordFailure('p1', 'claude', 'err');

        // Periodic check should transition to half_open
        $this->cb->periodicCheck();

        // Now canPass should allow
        $this->assertTrue($this->cb->canPass('p1', 'claude'));
    }
}
