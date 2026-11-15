<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class PostModel extends Model
{
    use RelationshipsTrait;

    protected $table          = 'posts';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $deletedField   = 'deleted_at';
    protected $allowedFields  = ['user_id', 'title', 'status', 'views', 'published_at', 'deleted_at'];

    public function author()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }

    public function authorWithDefault()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id')
            ->withDefault(['name' => 'Guest']);
    }

    public function comments()
    {
        return $this->morphMany(CommentModel::class, 'commentable');
    }

    public function image()
    {
        return $this->morphOne(ImageModel::class, 'imageable');
    }

    public function tags()
    {
        return $this->morphToMany(TagModel::class, 'taggable');
    }
}
