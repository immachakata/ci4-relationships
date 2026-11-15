<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class CategoryModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'categories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['parent_id', 'name', 'active'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }
}
