<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class VideoModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'videos';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['user_id', 'title'];

    public function user()
    {
        return $this->belongsTo(UserModel::class);
    }

    public function comments()
    {
        return $this->morphMany(CommentModel::class, 'commentable');
    }

    public function tags()
    {
        return $this->morphToMany(TagModel::class, 'taggable');
    }
}
