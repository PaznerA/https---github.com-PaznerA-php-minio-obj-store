<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Core;

interface VersionableInterface
{
    public function getId(): string|int;
    public function getVersionMetadata(): array;
    public function toStorageArray(): array;
    public function fromStorageArray(array $data): void;
}