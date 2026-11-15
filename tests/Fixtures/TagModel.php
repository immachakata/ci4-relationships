<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class TagModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'tags';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name'];

    public function posts()
    {
        return $this->morphedByMany(PostModel::class, 'taggable');
    }

    public function videos()
    {
        return $this->morphedByMany(VideoModel::class, 'taggable');
    }
}
