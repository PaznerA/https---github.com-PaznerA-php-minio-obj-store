<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Core;

use Illuminate\Support\Facades\Redis;
use Aws\S3\S3Client;

class VersionedStorage
{
    private S3Client $client;

    public function __construct(
        private VersionedStorageConfig $config
    ) {
        $this->client = new S3Client([
            'version' => 'latest',
            'region'  => $this->config->region,
            'endpoint' => $this->config->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => $this->config->accessKey,
                'secret' => $this->config->secretKey,
            ],
        ]);
    }

    public function store(VersionableInterface $entity): void
    {
        $id = $entity->getId();
        $data = $entity->toStorageArray();
        $metadata = $entity->getVersionMetadata();
        
        // Generate version ID
        $versionId = uniqid('v_');
        $timestamp = time();
        
        // Store data in MinIO
        $this->client->putObject([
            'Bucket' => $this->config->bucket,
            'Key' => $this->getObjectKey($id, $versionId),
            'Body' => json_encode($data),
            'ContentType' => 'application/json',
            'Metadata' => array_merge($metadata, [
                'version_id' => $versionId,
                'timestamp' => (string)$timestamp
            ])
        ]);

        // Update Redis metadata
        $redisKey = $this->getRedisKey($id);
        Redis::pipeline(function ($pipe) use ($redisKey, $versionId, $timestamp, $metadata) {
            // Store current version
            $pipe->hSet($redisKey, 'current_version', $versionId);
            $pipe->hSet($redisKey, 'updated_at', $timestamp);
            
            // Add version to sorted set
            $pipe->zAdd(
                $redisKey . ':versions',
                $timestamp,
                json_encode([
                    'version_id' => $versionId,
                    'timestamp' => $timestamp,
                    'metadata' => $metadata
                ])
            );
        });
    }

    public function load(string|int $id, ?string $versionId = null): array
    {
        if (!$versionId) {
            $versionId = Redis::hGet($this->getRedisKey($id), 'current_version');
            if (!$versionId) {
                throw new StorageException("Entity not found: {$id}");
            }
        }

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getObjectKey($id, $versionId)
            ]);

            return json_decode($result['Body'], true);
        } catch (\Exception $e) {
            throw new StorageException("Failed to load version {$versionId} of entity {$id}: " . $e->getMessage());
        }
    }

    public function getVersions(string|int $id, int $limit = 10, int $offset = 0): array
    {
        return Redis::zRevRange(
            $this->getRedisKey($id) . ':versions',
            $offset,
            $offset + $limit - 1,
            true
        );
    }

    public function delete(string|int $id): void
    {
        // Get all versions
        $versions = Redis::zRange($this->getRedisKey($id) . ':versions', 0, -1);
        
        // Delete from MinIO
        foreach ($versions as $versionData) {
            $version = json_decode($versionData, true);
            $this->client->deleteObject([
                'Bucket' => $this->config->bucket,
                'Key' => $this->getObjectKey($id, $version['version_id'])
            ]);
        }

        // Delete from Redis
        Redis::pipeline(function ($pipe) use ($id) {
            $redisKey = $this->getRedisKey($id);
            $pipe->del($redisKey);
            $pipe->del($redisKey . ':versions');
        });
    }

    private function getObjectKey(string|int $id, string $versionId): string
    {
        return "entities/{$id}/{$versionId}";
    }

    private function getRedisKey(string|int $id): string
    {
        return $this->config->redisPrefix . $id;
    }
}