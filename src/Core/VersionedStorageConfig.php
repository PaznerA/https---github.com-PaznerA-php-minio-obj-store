<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Core;

use Illuminate\Support\Facades\Redis;
use Aws\S3\S3Client;

class VersionedStorageConfig
{
    public function __construct(
        public string $endpoint,
        public string $accessKey,
        public string $secretKey,
        public string $bucket,
        public string $region = 'us-east-1',
        public bool $useSSL = true,
        public string $redisPrefix = 'versioned:'
    ) {}
}