<?php

namespace Tests\Fixtures;

class DefaultWithUserModel extends UserModel
{
    protected array $with      = ['profile'];
    protected array $withCount = ['posts'];
}
