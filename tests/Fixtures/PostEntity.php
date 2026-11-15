<?php

namespace Tests\Fixtures;

use CodeIgniter\Entity\Entity;
use IsaacMachakata\Ci4Relationships\EntityRelationshipsTrait;

class PostEntity extends Entity
{
    use EntityRelationshipsTrait;

    protected $casts = [
        'id'      => 'integer',
        'user_id' => '?integer',
        'views'   => 'integer',
    ];
}
