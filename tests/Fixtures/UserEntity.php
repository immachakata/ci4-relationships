<?php

namespace Tests\Fixtures;

use CodeIgniter\Entity\Entity;
use IsaacMachakata\Ci4Relationships\EntityRelationshipsTrait;

class UserEntity extends Entity
{
    use EntityRelationshipsTrait;

    protected $casts = [
        'id'         => 'integer',
        'country_id' => '?integer',
        'active'     => 'boolean',
    ];
}
