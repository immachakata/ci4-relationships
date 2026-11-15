<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use ArrayAccess;
use CodeIgniter\Entity\Entity;

/**
 * Represents a pivot table record on a many-to-many relationship.
 * Supports both object property access ($pivot->role) and array access ($pivot['role']).
 */
class Pivot extends Entity implements ArrayAccess
{
    /**
     * Pivot table name.
     *
     * @var string|null
     */
    protected $_table;

    /**
     * @param array<string, mixed> $data
     * @param string|null          $table
     */
    public function __construct(?array $data = null, ?string $table = null)
    {
        parent::__construct();

        $this->_table = $table;

        if ($data !== null) {
            $this->injectRawData($data);
        }
    }

    /**
     * Get the pivot table name.
     */
    public function getTable(): ?string
    {
        return $this->_table;
    }

    /**
     * @param mixed $offset
     */
    public function offsetExists($offset): bool
    {
        return is_string($offset) && $this->__isset($offset);
    }

    /**
     * @param mixed $offset
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return is_string($offset) ? $this->__get($offset) : null;
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        if (is_string($offset)) {
            $this->__set($offset, $value);
        }
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset): void
    {
        if (is_string($offset)) {
            $this->__unset($offset);
        }
    }
}
