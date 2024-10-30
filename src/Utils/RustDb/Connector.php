<?php declare(strict_types=1);

namespace Pazny\BtrfsStorageTesting\Utils\RustDb;

use FFI;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;
use JsonException;

class Connector implements \Stringable {
    
    /** @var ConnectionInterface */
    private FFI $ffi;
    private object $db;
    
    private const MAX_KEY_LENGTH = 256;
    private const KEY_PATTERN = '/^[a-zA-Z0-9_\-\/\*]+$/';
    private const PATH_PATTERN = '/^[a-zA-Z0-9_\-\/\*]+$/';
    private const MAX_PATH_DEPTH = 10;
    private const MAX_VALUE_LENGTH = 1024 * 1024; // 1MB

    /**
     * @throws RuntimeException
     */
    public function __construct(
        private readonly string $path,
        private readonly string $libraryPath = 'librust_db.so'
    ) {
        try {
            $this->ffi = FFI::cdef("
                void* db_create(const char* path);
                bool db_set(void* db, const char* key, const char* value);
                char* db_get(void* db, const char* key);
                bool db_delete(void* db, const char* key);
                int64_t db_increment(void* db, const char* key);
                bool db_backup(void* db, const char* path);
                bool db_restore(void* db, const char* path);
                bool db_export(void* db, const char* path);
                bool db_import(void* db, const char* path);
                bool db_init_volume(const char* path, uint64_t size_mb);
                char* db_find_by_path(void* db, char* pattern);
                void db_free_string(char* s);
                void db_destroy(void* db);
            ", $this->libraryPath);
            if(file_exists($this->path)) {
                $this->db = $this->ffi->db_create($path);
            } else {
                $this->initVolume($path, 1);
            }
            
            if ($this->db === null) {
                throw new RuntimeException("Failed to create database instance");
            }
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Database initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Validates a path string
     * 
     * @throws InvalidArgumentException
     */
    private function validatePath(string $path): void {
        if (empty($path)) {
            throw new InvalidArgumentException("Path cannot be empty");
        }

        if (!preg_match(self::PATH_PATTERN, $path)) {
            throw new InvalidArgumentException("Path contains invalid characters");
        }

        $depth = substr_count($path, '/') + 1;
        if ($depth > self::MAX_PATH_DEPTH) {
            throw new InvalidArgumentException("Path exceeds maximum depth of " . self::MAX_PATH_DEPTH);
        }
    }

    /**
     * Inicializuje nový Btrfs svazek pro databázi
     * 
     * @throws RuntimeException
     */
    public function initVolume(string $path, int $sizeMb): void {
        if ($sizeMb < 1) {
            throw new InvalidArgumentException("Size must be at least 1 MB");
        }

        try {
            if (!$this->ffi->db_init_volume($path, $sizeMb)) {
                throw new RuntimeException("Failed to initialize Btrfs volume");
            }
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Volume initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Validuje klíč
     * 
     * @throws InvalidArgumentException
     */
    private function validateKey(string $key): void {
        if (empty($key)) {
            throw new InvalidArgumentException("Key cannot be empty");
        }

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException("Key is too long (max " . self::MAX_KEY_LENGTH . " characters)");
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException("Key contains invalid characters (allowed: a-z, A-Z, 0-9, _, :, -)");
        }
    }

    /**
     * Serializuje hodnotu do formátu pro uložení
     */
    private function serializeValue(mixed $value, ?ValueType $type = null): string {
        if ($type === null) {
            $type = match(true) {
                is_string($value) => ValueType::String,
                is_int($value) => ValueType::Integer,
                is_float($value) => ValueType::Float,
                is_bool($value) => ValueType::Boolean,
                is_array($value) => ValueType::Array,
                $value === null => ValueType::Null,
                default => throw new InvalidArgumentException("Unsupported value type")
            };
        }

        $serialized = match($type) {
            ValueType::String => $value,
            ValueType::Integer => (string)$value,
            ValueType::Float => (string)$value,
            ValueType::Boolean => $value ? 'true' : 'false',
            ValueType::Array => json_encode($value, JSON_THROW_ON_ERROR),
            ValueType::Map => json_encode($value, JSON_THROW_ON_ERROR),
            ValueType::Null => 'null'
        };

        if (strlen($serialized) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException("Serialized value is too large (max " . self::MAX_VALUE_LENGTH . " bytes)");
        }

        return $serialized;
    }

    /**
     * Deserializuje hodnotu z uloženého formátu
     */
    private function deserializeValue(string $value): mixed {
        // Pokus o JSON decode
        if (in_array($value[0] ?? '', ['{', '['])) {
            try {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Pokud selže JSON decode, vrátíme hodnotu jako string
            }
        }

        // Pokus o konverzi na základní typy
        if ($value === 'null') return null;
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        if (is_numeric($value)) {
            // Zachování celých čísel jako int
            if ((string)(int)$value === $value) return (int)$value;
            // Float hodnoty
            if ((string)(float)$value === $value) return (float)$value;
        }

        // Výchozí návrat jako string
        return $value;
    }

    /**
     * Vytvoří zálohu databáze
     * 
     * @throws RuntimeException
     */
    public function backup(string $path): void {
        try {
            if (!$this->ffi->db_backup($this->db, $path)) {
                throw new RuntimeException("Backup failed");
            }
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Backup failed: " . $e->getMessage());
        }
    }

    /**
     * Obnoví databázi ze zálohy
     * 
     * @throws RuntimeException
     */
    public function restore(string $path): void {
        try {
            if (!$this->ffi->db_restore($this->db, $path)) {
                throw new RuntimeException("Restore failed");
            }
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Restore failed: " . $e->getMessage());
        }
    }

    /**
     * Exportuje databázi do JSON souboru
     * 
     * @throws RuntimeException
     */
    public function export(string $path): void {
        try {
            if (!$this->ffi->db_export($this->db, $path)) {
                throw new RuntimeException("Export failed");
            }
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Export failed: " . $e->getMessage());
        }
    }

    /**
     * Importuje databázi z JSON souboru
     * 
     * @throws RuntimeException
     */
    public function import(string $path): void {
        try {
            if (!$this->ffi->db_import($this->db, $path)) {
                throw new RuntimeException("Import failed");
            }
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Import failed: " . $e->getMessage());
        }
    }

    /**
     * Sets a value at the specified path
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function set(string $path, mixed $value, ?ValueType $type = null): bool {
        $this->validatePath($path);
        $serialized = $this->serializeValue($value, $type);
        
        try {
            return $this->ffi->db_set($this->db, $path, $serialized);
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Set operation failed: " . $e->getMessage());
        }
    }

    /**
     * Získá hodnotu pro klíč
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function get(string $key): mixed {
        $this->validateKey($key);
        
        try {
            $result = $this->ffi->db_get($this->db, $key);
            if ($result === null) {
                return null;
            }
            
            $value = FFI::string($result);
            $this->ffi->db_free_string($result);
            
            return $this->deserializeValue($value);
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Get operation failed: " . $e->getMessage());
        }
    }

    /**
     * Finds entries matching the path pattern
     * 
     * @throws RuntimeException|InvalidArgumentException
     * @return array<string, mixed>
     */
    public function findByPath(string $pattern): array {
        $this->validatePath($pattern);
        
        try {
            $result = $this->ffi->db_find_by_path($this->db, $pattern);
            if ($result === null) {
                return [];
            }
            
            $json = FFI::string($result);
            $this->ffi->db_free_string($result);
            
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Find operation failed: " . $e->getMessage());
        }
    }

    /**
     * Gets all entries under a specific path
     * 
     * @throws RuntimeException|InvalidArgumentException
     * @return array<int, string>
     */
    public function listDirectory(string $prefix): array {
        $this->validatePath($prefix);
        
        try {
            $result = $this->ffi->db_list_directory($this->db, $prefix);
            if ($result === null) {
                return [];
            }
            
            $json = FFI::string($result);
            $this->ffi->db_free_string($result);
            
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("List operation failed: " . $e->getMessage());
        }
    }

    /**
     * Remove database entry by key
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function delete(string $key): bool {
        $this->validateKey($key);
        
        try {
            return $this->ffi->db_delete($this->db, $key);
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Delete operation failed: " . $e->getMessage());
        }
    }

    /**
     * Inkrementuje číselnou hodnotu
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function increment(string $path): int {
        $this->validatePath($path);
        
        try {
            $result = $this->ffi->db_increment($this->db, $path);
            if ($result === -1) {
                throw new RuntimeException("Increment failed - path does not exist or value is not a number");
            }
            return $result;
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Increment operation failed: " . $e->getMessage());
        }
    }

    /**
     * Nastaví expiraci pro hodnotu
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function setExpiry(string $path, int $seconds): bool {
        $this->validatePath($path);
        
        try {
            return $this->ffi->db_set_expiry($this->db, $path, $seconds);
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Set expiry operation failed: " . $e->getMessage());
        }
    }

    /**
     * Získá čas do expirace hodnoty (TTL)
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function ttl(string $path): ?int {
        $this->validatePath($path);
        
        try {
            $result = $this->ffi->db_ttl($this->db, $path);
            return $result >= 0 ? $result : null;
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("TTL operation failed: " . $e->getMessage());
        }
    }

    /**
     * Zkontroluje existenci klíče
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function exists(string $path): bool {
        $this->validatePath($path);
        
        try {
            return $this->ffi->db_exists($this->db, $path);
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Exists operation failed: " . $e->getMessage());
        }
    }

    /**
     * Smaže všechny záznamy odpovídající vzoru
     * 
     * @throws RuntimeException|InvalidArgumentException
     */
    public function deleteByPattern(string $pattern): int {
        $this->validatePath($pattern);
        
        try {
            $result = $this->ffi->db_delete_by_pattern($this->db, $pattern);
            if ($result === -1) {
                throw new RuntimeException("Delete by pattern failed");
            }
            return $result;
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Delete by pattern operation failed: " . $e->getMessage());
        }
    }

    /**
     * Získá detailní statistiky databáze
     * 
     * @throws RuntimeException
     * @return array<string, mixed>
     */
    public function getDetailedStats(): array {
        try {
            $result = $this->ffi->db_get_detailed_stats($this->db);
            if ($result === null) {
                throw new RuntimeException("Failed to get detailed stats");
            }
            
            $json = FFI::string($result);
            $this->ffi->db_free_string($result);
            
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\FFI\Exception $e) {
            throw new RuntimeException("Get detailed stats operation failed: " . $e->getMessage());
        }
    }

    /**
     * Stringable interface - returns database path
     */
    public function __toString(): string {
        return $this->path;
    }

    public function __destruct() {
        if (isset($this->db) && $this->db !== null) {
            try {
                $this->ffi->db_destroy($this->db);
            } catch (\FFI\Exception $e) {
                error_log("Error destroying database: " . $e->getMessage());
            }
        }
    }
}