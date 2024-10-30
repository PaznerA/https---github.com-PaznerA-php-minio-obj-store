<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Models;

use Exception;
use Pazny\BtrfsStorageTesting\Utils\Config;
use Pazny\BtrfsStorageTesting\Utils\Database;
use Pazny\BtrfsStorageTesting\Utils\MinioStorageAPI;

class Article {
    private Database $db;
    private Config $config;

    public function __construct(Config $config) {
        $this->config = $config;
        $this->db = new Database($config);
    }

    public function create($title, $content, $authorId): string {
        try {
            $namespace = 'articles/' . uniqid();
            $versionId = $this->db->set($namespace, $content);
            return $namespace;
        } catch (Exception $e) {
            throw new Exception("Failed to create article: " . $e->getMessage());
        }
    }

    public function read(string $namespace, $versionId = null) {
        try {
            return $this->db->get($namespace, $versionId);
        } catch (Exception $e) {
            throw new Exception("Failed to get article: " . $e->getMessage());
        }
    }

    public function update(string $namespace, $title, $content) {
        try {
            // $versionId = $this->db->update(namespace: $namespace, content: $content);
            // return $versionId;
        } catch (Exception $e) {
            throw new Exception("Failed to update article: " . $e->getMessage());
        }
    }
    public function delete($articleId, $versionId = null) {
            // $this->db->unset($articleId, $versionId);
    }

    public function getVersionHistory($articleId) {
        try {
            return $this->db->listVersions($articleId);
        } catch (Exception $e) {
            throw new Exception("Failed to get version history: " . $e->getMessage());
        }
    }
}