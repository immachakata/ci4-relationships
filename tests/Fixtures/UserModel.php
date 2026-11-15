<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class UserModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['country_id', 'name', 'email', 'active'];

    public function country()
    {
        return $this->belongsTo(CountryModel::class);
    }

    public function profile()
    {
        return $this->hasOne(ProfileModel::class);
    }

    public function profileWithDefault()
    {
        return $this->hasOne(ProfileModel::class)->withDefault(['bio' => 'Default Bio']);
    }

    public function posts()
    {
        return $this->hasMany(PostModel::class);
    }

    public function publishedPosts()
    {
        return $this->hasMany(PostModel::class)->where('status', 'published');
    }

    public function latestPost()
    {
        return $this->hasOne(PostModel::class)->latestOfMany();
    }

    public function oldestPost()
    {
        return $this->hasOne(PostModel::class)->oldestOfMany();
    }

    public function topPost()
    {
        return $this->hasOne(PostModel::class)->ofMany('views', 'max');
    }

    public function roles()
    {
        return $this->belongsToMany(RoleModel::class)
            ->withPivot('assigned_by', 'role_label')
            ->withTimestamps();
    }

    public function videos()
    {
        return $this->hasMany(VideoModel::class);
    }
}
