<?php declare(strict_types= 1);

namespace Pazny\BtrfsStorageTesting\Models;

use Illuminate\Database\Eloquent\Model;
use Pazny\BtrfsStorageTesting\Core\HasVersionedStorage;
use Pazny\BtrfsStorageTesting\Core\VersionableInterface;


class LaravelArticle extends Model implements VersionableInterface
{
    use HasVersionedStorage;

    protected $fillable = [
        'title',
        'content',
        'author_id'
    ];

    // Přizpůsobení toho, co se má verzovat
    public function toStorageArray(): array
    {
        return [
            'title' => $this->title,
            'content' => $this->content,
            'author_id' => $this->author_id
        ];
    }

    // Volitelné přizpůsobení metadat verze
    public function getVersionMetadata(): array
    {
        return array_merge(parent::getVersionMetadata(), [
            'title' => $this->title // přidáme title do metadat pro snazší identifikaci verzí
        ]);
    }
}