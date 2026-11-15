<?php

namespace Tests\Fixtures;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class CommentModel extends Model
{
    use RelationshipsTrait;

    protected $table          = 'comments';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = true;
    protected $allowedFields  = ['commentable_id', 'commentable_type', 'user_id', 'body', 'approved', 'deleted_at'];

    public function commentable()
    {
        return $this->morphTo();
    }

    public function author()
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }
}
