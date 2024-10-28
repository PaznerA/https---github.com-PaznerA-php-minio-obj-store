<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Models;

use Pazny\BtrfsStorageTesting\Utils\Database;
use Pazny\BtrfsStorageTesting\Utils\MinioStorageAPI as BtrfsStorageAPI;
use Exception;
use PDO;
use Pazny\BtrfsStorageTesting\Utils\Config;
use Pazny\BtrfsStorageTesting\Utils\MinioStorageAPI;

class Article {
    // private Database $db;
    private MinioStorageAPI $storage;

    private Config $config;

    public function __construct(MinioStorageAPI $storage, Config $config) {
        $this->config = $config;
        // $this->db = $db;
        $this->storage = $storage;
    }

    public function create($title, $content, $authorId): string {
        try {
            $namespace = 'articles/' . uniqid();
            
            // Store content in BTRFS storage
            $versionId = $this->storage->store($namespace, $content);
            return $namespace;
            // // Store metadata in SQL database
            // $stmt = $this->db->getPdo()->prepare(
            //     "INSERT INTO articles (title, namespace, current_version, author_id, created_at) 
            //      VALUES (?, ?, ?, ?, NOW())"
            // );
            // $stmt->execute([$title, $namespace, $versionId, $authorId]);
            // return $this->db->getPdo()->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to create article: " . $e->getMessage());
        }
    }

    public function update($articleId, $title, $content) {
        try {
            // Get existing article metadata
            // $stmt = $this->db->getPdo()->prepare(
            //     "SELECT namespace FROM articles WHERE id = ?"
            // );
            // $stmt->execute([$articleId]);
            // $article = $stmt->fetch(PDO::FETCH_ASSOC);

            // if (!$article) {
            //     throw new Exception("Article not found");
            // }

            // Store new version in BTRFS storage
            $versionId = $this->storage->store(namespace: $articleId, content: $content);

            // Update metadata in SQL database
            // $stmt = $this->db->getPdo()->prepare(
            //     "UPDATE articles 
            //      SET title = ?, current_version = ?, updated_at = NOW() 
            //      WHERE id = ?"
            // );
            // $stmt->execute([$title, $versionId, $articleId]);

            return $versionId;
        } catch (Exception $e) {
            throw new Exception("Failed to update article: " . $e->getMessage());
        }
    }

    public function get($articleId, $versionId = null) {
        try {
            // // Get article metadata
            // $stmt = $this->db->getPdo()->prepare(
            //     "SELECT * FROM articles WHERE id = ?"
            // );
            // $stmt->execute([$articleId]);
            // $article = $stmt->fetch(PDO::FETCH_ASSOC);

            // if (!$article) {
            //     throw new Exception("Article not found");
            // }

            // Get content from storage
            $version = $versionId ?? 1;
            $content = $this->storage->retrieve($articleId, $version);

            return [
                // 'id' => $article['id'],
                // 'title' => $article['title'],
                'content' => $content['content'],
                // 'version' => $version,
                // 'author_id' => $article['author_id'],
                // 'created_at' => $article['created_at'],
                // 'updated_at' => $article['updated_at']
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to get article: " . $e->getMessage());
        }
    }

    public function getVersionHistory($articleId) {
        try {
            // Get article namespace
            // $stmt = $this->db->getPdo()->prepare(
            //     "SELECT namespace FROM articles WHERE id = ?"
            // );
            // $stmt->execute([$articleId]);
            // $article = $stmt->fetch(PDO::FETCH_ASSOC);

            // if (!$article) {
            //     throw new Exception("Article not found");
            // }

            // Get versions from storage
            return $this->storage->listVersions($articleId);
        } catch (Exception $e) {
            throw new Exception("Failed to get version history: " . $e->getMessage());
        }
    }
}