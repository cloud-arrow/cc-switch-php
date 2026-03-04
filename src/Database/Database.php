<?php

declare(strict_types=1);

namespace CcSwitch\Database;

use Medoo\Medoo;
use PDO;

/**
 * Thin wrapper around PDO and Medoo database connections.
 */
class Database
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Medoo $medoo,
    ) {
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function getMedoo(): Medoo
    {
        return $this->medoo;
    }
}
