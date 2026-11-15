<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class ProfileModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'profiles';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['user_id', 'bio', 'avatar_url'];

    public function user()
    {
        return $this->belongsTo(UserModel::class);
    }
}
