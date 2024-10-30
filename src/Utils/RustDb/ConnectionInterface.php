<?php declare(strict_types=1);

namespace Pazny\BtrfsStorageTesting\Utils\RustDb;

use FFI\CData;

interface ConnectionInterface {
    public function db_create(string $path);
    public function db_set(object $db, string $key, string $value): bool;
    public function db_get(object $db, string $key);
    public function db_find_by_path(object $db, string $path): ?object;
    public function db_list_directory(object $db, string $path): CData;
    public function db_delete_by_pattern(object $db, string $path): int;
    public function db_delete(object $db, string $key): bool;
    public function db_increment(object $db, string $key): int;
    public function db_backup(object $db, string $path): bool;
    public function db_restore(object $db, string $path): bool;
    public function db_export(object $db, string $path): bool;
    public function db_exists(object $db, string $path): bool;
    public function db_ttl(object $db, string $path): bool;
    public function db_import(object $db, string $path): bool;
    public function db_init_volume(string $path, int $size_mb): bool;
    public function db_free_string(object $db): void;
    public function db_set_expiry(object $db, string $path, int $seconds): bool;
    public function db_destroy(object $db): void;
    public function db_get_stats(object $db): Stats;
    public function db_get_detailed_stats(object $db): CData;
}