<?php declare(strict_types=1);

namespace Pazny\BtrfsStorageTesting\Utils;

use Pazny\BtrfsStorageTesting\Models\Article;
class ArticleManager {
    private MinioStorageAPI $storage;
    private Config $config;

    private Database $db;

    public function __construct() {
        $this->config = new Config(
            'localhost:9000',
            'heslo123',
            'localhost',
            'crm_db',
            'user',
            'password'
        );
        $this->storage = new MinioStorageAPI($this->config);
        $this->db = new Database($this->config);
    }

    public function createArticle($title, $content, $authorId): string {
        $article = new Article($this->config);
        $namespace = $article->create($title, $content, $authorId);
        echo "Article created with namespace: $namespace\n";
        return $namespace;
    }

    public function retrieveArticle($data) {
        $article = new Article($this->config);
        $data = $article->read($data["namespace"]);
        echo "Article Content: " . $data['content'] . "\n";
    }

    public function updateArticle($data) {

        $article = new Article($this->config);
        $versionId = $article->update($data["namespace"], $data["title"], $data["content"]);
        echo "Article updated. New version ID: $versionId\n";
    }

    public function listArticleVersions($data) {
        $versions = $this->storage->listVersions($data["namespace"]);
        
        foreach ($versions as $version) {
            echo "Version ID: {$version['version_id']}, Last Modified: {$version['timestamp']}, Size: {$version['size']} bytes\n";
        }
    }
}