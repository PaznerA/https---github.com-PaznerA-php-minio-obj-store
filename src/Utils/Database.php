<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Utils;

use Pazny\BtrfsStorageTesting\Utils\RustDb;

class Database {
    
    private RustDb $memoryConnection; //implement rollUp for loading data from storage and creation of internal "SYS. DB"
    private MinioStorageAPI $storageApi;

    public function __construct(Config $config) {
        try {
            $this->memoryConnection = new RustDb(path: '/data/db/storage.json');
        } catch (\Throwable $e) {
            throw new \Exception(message: "Memory storage connection failed: " . $e->getMessage());
        }

        try {
            $this->storageApi = new MinioStorageAPI($config);
        } catch (\Throwable $e) {
            throw new \Exception(message: "File storage connection failed: " . $e->getMessage());
        }

    }

    private function _conn(): RustDb {
        return $this->memoryConnection;
    }

    public function listVersions(string $namespace): array 
    {
        //TODO: rework after rollUp
        return $this->storageApi->listVersions($namespace);  
    }
    

    public function set(string $namespace, mixed $value): void 
    {
        $oldVal = $this->memoryConnection->get($namespace);
        if($oldVal !== $value) {
            $memoryUpdated = self::_conn()->set($namespace, $value);
            if($memoryUpdated){
                $this->storageApi->store($namespace, $value);  
            }
        }
    }
    public function get(string $namespace, ?int $versionId = null): mixed 
    {
        $value = self::_conn()->get($namespace);
        if($value !== null) {
            return $value;
        }
        $values = $this->storageApi->retrieve($namespace, $versionId);
        if($values !== null) {
            $value = $values[array_key_last($values)];
            self::_conn()->set($namespace, $value); 
        }
        return $value[array_key_last($value)];
    }
    
    public function update(string $namespace, mixed $value): void
    {
        $oldVal = $this->memoryConnection->get($namespace);
        if($oldVal !== $value) {
            $memoryUpdated = self::_conn()->set($namespace, $value);
            if($memoryUpdated){
                $this->storageApi->store($namespace, $value);
            }
        }
    }
}