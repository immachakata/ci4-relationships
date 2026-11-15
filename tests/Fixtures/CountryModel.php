<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class CountryModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'countries';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name'];

    public function users()
    {
        return $this->hasMany(UserModel::class);
    }

    public function posts()
    {
        return $this->hasManyThrough(PostModel::class, UserModel::class);
    }

    public function latestPost()
    {
        return $this->hasOneThrough(PostModel::class, UserModel::class)->orderBy('posts.id', 'DESC');
    }
}
