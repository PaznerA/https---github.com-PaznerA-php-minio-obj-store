<?php declare(strict_types=1);

namespace Pazny\BtrfsStorageTesting\Utils;

class Config {
    private string $storageApiUrl;
    private string $storageApiKey;
    private string $dbHost;
    private string $dbName;
    private string $dbUser;
    private string $dbPass;

    public function __construct(
        string $storageApiUrl,
        string $storageApiKey,
        string $dbHost,
        string $dbName,
        string $dbUser,
        string $dbPass
    ) {
        $this->storageApiUrl = $storageApiUrl;
        $this->storageApiKey = $storageApiKey;
        $this->dbHost = $dbHost;
        $this->dbName = $dbName;
        $this->dbUser = $dbUser;
        $this->dbPass = $dbPass;
    }

    public function getStorageApiUrl(): string {
        return $this->storageApiUrl;
    }

    public function getStorageApiKey(): string {
        return $this->storageApiKey;
    }

    public function getDbHost(): string {
        return $this->dbHost;
    }

    public function getDbName(): string {
        return $this->dbName;
    }

    public function getDbUser(): string {
        return $this->dbUser;
    }

    public function getDbPass(): string {
        return $this->dbPass;
    }
}
