<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class RoleModel extends Model
{
    use RelationshipsTrait;

    protected $table          = 'roles';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $allowedFields  = ['name', 'deleted_at'];

    public function users()
    {
        return $this->belongsToMany(UserModel::class)
            ->withPivot('assigned_by', 'role_label');
    }
}
