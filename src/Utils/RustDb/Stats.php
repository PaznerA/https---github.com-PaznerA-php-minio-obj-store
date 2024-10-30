<?php declare(strict_types=1);

namespace Pazny\BtrfsStorageTesting\Utils\RustDb;

use \DateTimeImmutable;

class Stats {
    public function __construct(
        public readonly int $totalKeys,
        public readonly int $expiredKeys,
        public readonly int $memoryUsage,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $lastBackup
    ) {}
}