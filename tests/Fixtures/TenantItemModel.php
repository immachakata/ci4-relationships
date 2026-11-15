<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class TenantItemModel extends Model
{
    use RelationshipsTrait;

    protected $table            = 'tenant_items';
    protected $primaryKey       = 'uuid';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $allowedFields    = ['uuid', 'tenant_uuid', 'label'];

    public function tenant()
    {
        return $this->belongsTo(TenantModel::class, 'tenant_uuid', 'uuid');
    }
}
