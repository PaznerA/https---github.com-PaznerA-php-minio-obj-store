<?php

namespace Pazny\BtrfsStorageTesting\Utils;

use FFI;

class RustDb {
    private $db;
    private $ffi;

    public function __construct(string $path) {
        try {
            $this->ffi = FFI::cdef("
                void* db_create(const char* path);
                bool db_set(void* db, const char* key, const char* value);
                char* db_get(void* db, const char* key);
                bool db_delete(void* db, const char* key);
                int64_t db_increment(void* db, const char* key);
                void db_free_string(char* s);
                void db_destroy(void* db);
            ", "librust_db.so");
            
            $this->db = $this->ffi->db_create($path);
            
            if ($this->db === null) {
                throw new \Exception("Failed to create database instance");
            }
        } catch (\FFI\Exception $e) {
            throw new \Exception("Database initialization failed: " . $e->getMessage());
        }
    }

    public function set(string $key, string $value): bool {
        try {
            return $this->ffi->db_set($this->db, $key, $value);
        } catch (\FFI\Exception $e) {
            throw new \Exception("Set operation failed: " . $e->getMessage());
        }
    }

    public function get(string $key): ?string {
        try {
            $result = $this->ffi->db_get($this->db, $key);
            if ($result === null) {
                return null;
            }
            $value = FFI::string($result);
            $this->ffi->db_free_string($result);
            return $value;
        } catch (\FFI\Exception $e) {
            throw new \Exception("Get operation failed: " . $e->getMessage());
        }
    }

    public function delete(string $key): bool {
        try {
            return $this->ffi->db_delete($this->db, $key);
        } catch (\FFI\Exception $e) {
            throw new \Exception("Delete operation failed: " . $e->getMessage());
        }
    }

    public function increment(string $key): int {
        try {
            return $this->ffi->db_increment($this->db, $key);
        } catch (\FFI\Exception $e) {
            throw new \Exception("Increment operation failed: " . $e->getMessage());
        }
    }

    public function __destruct() {
        if ($this->db !== null) {
            try {
                $this->ffi->db_destroy($this->db);
            } catch (\FFI\Exception $e) {
                // Log error při destrukci
                error_log("Error destroying database: " . $e->getMessage());
            }
        }
    }
}