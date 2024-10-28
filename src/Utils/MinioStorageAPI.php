<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Utils;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class MinioStorageAPI {
    private $s3Client;
    private $bucket = 'crm-articles';

    public function __construct(Config $config) {
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region'  => 'us-east-1', // Může být cokoliv pro MinIO
            'endpoint' => 'http://minio:9000', // Název služby v docker-compose
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key'    => 'admin',
                'secret' => 'heslo123',
            ],
        ]);

        // Vytvoření bucketu, pokud neexistuje
        if (!$this->s3Client->doesBucketExist($this->bucket)) {
            $this->s3Client->createBucket(['Bucket' => $this->bucket]);
            // Zapnutí verzování
            $this->s3Client->putBucketVersioning([
                'Bucket' => $this->bucket,
                "VersioningConfiguration" => [
                    'Status' => 'Enabled',
                ]
            ]);
        }
    }

    public function store($namespace, $content, $metadata = []) {
        try {
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $namespace,
                'Body'   => $content,
                'Metadata' => $metadata
            ]);
            
            return $result['VersionId'];
        } catch (AwsException $e) {
            throw new \Exception("Failed to store object: " . $e->getMessage());
        }
    }

    public function retrieve($namespace, $versionId = null) {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key'    => $namespace
            ];

            if ($versionId) {
                $params['VersionId'] = $versionId;
            }

            $result = $this->s3Client->getObject($params);
            
            return [
                'content' => (string) $result['Body'],
                'metadata' => $result['Metadata']
            ];
        } catch (AwsException $e) {
            throw new \Exception("Failed to retrieve object: " . $e->getMessage());
        }
    }

    public function listVersions($namespace) {
        try {
            $results = $this->s3Client->listObjectVersions([
                'Bucket' => $this->bucket,
                'Prefix' => $namespace
            ]);

            $versions = [];
            foreach ($results['Versions'] as $version) {
                $versions[] = [
                    'version_id' => $version['VersionId'],
                    'timestamp' => $version['LastModified']->format('c'),
                    'size' => $version['Size']
                ];
            }

            return $versions;
        } catch (AwsException $e) {
            throw new \Exception("Failed to list versions: " . $e->getMessage());
        }
    }
}