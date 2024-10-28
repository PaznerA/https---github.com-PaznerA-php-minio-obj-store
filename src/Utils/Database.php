<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Utils;

use PDO;
use PDOException;

class Database {
    private $pdo;

    public function __construct(Config $config) {
        var_dump($config);
        try {
            $this->pdo = new PDO(
                "mysql:host=" . $config->getDbHost() . ";dbname=" . $config->getDbName(),
                $config->getDbUser(),
                password: $config->getDbPass()
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public function getPdo() {
        return $this->pdo;
    }
}