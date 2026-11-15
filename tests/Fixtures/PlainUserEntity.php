<?php

namespace Tests\Fixtures;

use CodeIgniter\Entity\Entity;

class PlainUserEntity extends Entity
{
    protected $casts = [
        'id' => 'integer',
    ];
}
