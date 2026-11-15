<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class ImageModel extends Model
{
    use RelationshipsTrait;

    protected $table         = 'images';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['imageable_id', 'imageable_type', 'url', 'is_primary'];

    public function imageable()
    {
        return $this->morphTo();
    }
}
