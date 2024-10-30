<?php 

require "./vendor/autoload.php";

use Pazny\BtrfsStorageTesting\Utils\Config;
use Pazny\BtrfsStorageTesting\Utils\Database;
use Pazny\BtrfsStorageTesting\Models\Article;
use Pazny\BtrfsStorageTesting\Utils\MinioStorageAPI;

$config = new Config(
    'localhost:9000',
    "heslo123",
    'localhost',
    'crm_db',
    'user',
    'password'
);

// $config = new Config(
//    getenv("STORAGE_API_URL"),
//    getenv("STORAGE_API_KEY"),
//     'localhost',
//     'crm_db',
//     'user',
//     'password'
// );


// $database = new Database($config);
$storage = new MinioStorageAPI($config);

var_dump($database->get('ssss'));

try {
    $article = new Article( $storage, $config); // Assuming Article class is modified to accept Config

    // Vytvoření nového článku
    $articleId = $article->create(
        "Můj první článek",
        "Obsah článku...",
        1 // ID autora
    );

    // Získání článku
    $articleData = $article->get($articleId);
    
    // Aktualizace článku
    $newVersionId = $article->update(
        $articleId,
        "Upravený název",
        "Nový obsah článku..."
    );

    // Získání historie verzí
    $versions = $article->getVersionHistory($articleId);

    // Získání konkrétní verze článku
    $oldVersion = $article->get($articleId, $versions[0]['version_id']);

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
