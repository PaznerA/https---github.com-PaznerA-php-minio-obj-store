<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Core;

trait HasVersionedStorage
{
    protected static ?VersionedStorage $storage = null;
    
    protected static function initStorage(): void
    {
        if (self::$storage === null) {
            self::$storage = new VersionedStorage(
                new VersionedStorageConfig(
                    endpoint: env('storage.minio.endpoint'),
                    accessKey: env('storage.minio.key'),
                    secretKey: env('storage.minio.secret'),
                    bucket: env('storage.minio.bucket')
                )
            );
        }
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    public function getVersionMetadata(): array
    {
        return [
            //todo: connect with laravel auth trait?
            // 'author_id' => auth()->id() ?? 0,
            'model' => get_class($this)
        ];
    }

    public function toStorageArray(): array
    {
        return $this->toArray();
    }

    public function fromStorageArray(array $data): void
    {
        $this->fill($data);
    }

    public function storeVersion(): void
    {
        self::initStorage();
        self::$storage->store($this);
    }

    public function loadVersion(?string $versionId = null): void
    {
        self::initStorage();
        $data = self::$storage->load($this->getId(), $versionId);
        $this->fromStorageArray($data);
    }

    public function getVersions(int $limit = 10, int $offset = 0): array
    {
        self::initStorage();
        return self::$storage->getVersions($this->getId(), $limit, $offset);
    }

    public function deleteVersions(): void
    {
        self::initStorage();
        self::$storage->delete($this->getId());
    }
}