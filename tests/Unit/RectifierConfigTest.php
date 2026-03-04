<?php

declare(strict_types=1);

namespace CcSwitch\Tests\Unit;

use CcSwitch\Database\Migrator;
use CcSwitch\Database\Repository\SettingsRepository;
use Medoo\Medoo;
use PDO;
use PHPUnit\Framework\TestCase;

class RectifierConfigTest extends TestCase
{
    private PDO $pdo;
    private Medoo $medoo;
    private SettingsRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys=ON');

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        $migrator = new Migrator($this->pdo, $migrationsDir);
        $migrator->migrate();

        $this->medoo = new Medoo([
            'type' => 'sqlite',
            'database' => ':memory:',
            'pdo' => $this->pdo,
        ]);

        $this->repo = new SettingsRepository($this->medoo);
    }

    public function testGetRectifierDefaultsToEnabled(): void
    {
        $signatureEnabled = ($this->repo->get('rectifier_signature_enabled') ?? '1') === '1';
        $budgetEnabled = ($this->repo->get('rectifier_budget_enabled') ?? '1') === '1';

        $this->assertTrue($signatureEnabled);
        $this->assertTrue($budgetEnabled);
    }

    public function testSetRectifierSignatureDisabled(): void
    {
        $this->repo->set('rectifier_signature_enabled', '0');

        $value = $this->repo->get('rectifier_signature_enabled');
        $this->assertSame('0', $value);
        $this->assertFalse($value === '1');
    }

    public function testSetRectifierBudgetDisabled(): void
    {
        $this->repo->set('rectifier_budget_enabled', '0');

        $value = $this->repo->get('rectifier_budget_enabled');
        $this->assertSame('0', $value);
    }

    public function testSetRectifierReEnabled(): void
    {
        $this->repo->set('rectifier_signature_enabled', '0');
        $this->assertSame('0', $this->repo->get('rectifier_signature_enabled'));

        $this->repo->set('rectifier_signature_enabled', '1');
        $this->assertSame('1', $this->repo->get('rectifier_signature_enabled'));
    }

    public function testGetRectifierReturnsCorrectShape(): void
    {
        $this->repo->set('rectifier_signature_enabled', '0');
        $this->repo->set('rectifier_budget_enabled', '1');

        $result = [
            'signature_enabled' => ($this->repo->get('rectifier_signature_enabled') ?? '1') === '1',
            'budget_enabled' => ($this->repo->get('rectifier_budget_enabled') ?? '1') === '1',
        ];

        $this->assertFalse($result['signature_enabled']);
        $this->assertTrue($result['budget_enabled']);
    }

    public function testSetBothRectifierSettings(): void
    {
        $this->repo->set('rectifier_signature_enabled', '0');
        $this->repo->set('rectifier_budget_enabled', '0');

        $this->assertSame('0', $this->repo->get('rectifier_signature_enabled'));
        $this->assertSame('0', $this->repo->get('rectifier_budget_enabled'));
    }
}
