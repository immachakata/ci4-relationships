<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class TenantModel extends Model
{
    use RelationshipsTrait;

    protected $table            = 'tenants';
    protected $primaryKey       = 'uuid';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['uuid', 'name'];

    public function items()
    {
        return $this->hasMany(TenantItemModel::class);
    }
}
