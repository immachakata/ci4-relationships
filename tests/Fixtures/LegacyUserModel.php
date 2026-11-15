<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class LegacyUserModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'users';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['country_id', 'name', 'email', 'active'];

    public function initialize()
    {
        $this->hasOne('avatar', ProfileModel::class, 'user_id');
        $this->hasMany('links', PostModel::class, 'user_id');
    }
}
