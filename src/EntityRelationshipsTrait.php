<?php

namespace IsaacMachakata\Ci4Relationships;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\Relations\Relation;

/**
 * Optional trait for CodeIgniter\Entity\Entity subclasses to enable:
 * - Lazy loading on property access ($user->posts)
 * - Relation query access ($user->posts()->where(...)->findAll())
 * - Lazy eager loading ($user->load('posts'), $user->loadMissing('profile'))
 * - Clean separation between database attributes and loaded relations (save() & hasChanged() safe)
 */
trait EntityRelationshipsTrait
{
    /**
     * Loaded relationship data.
     *
     * @var array<string, mixed>
     */
    protected array $_relations = [];

    /**
     * Model class associated with this entity (set automatically on load, or defined on the Entity).
     *
     * @var string|null
     */
    protected ?string $_modelClass = null;

    /**
     * Optional database connection associated with the model that loaded this entity.
     *
     * @var \CodeIgniter\Database\ConnectionInterface|null
     */
    protected $_modelConnection = null;

    /**
     * Associate a Model class (and optional DB connection) with this Entity instance.
     *
     * @param \CodeIgniter\Database\ConnectionInterface|null $connection
     */
    public function setModelClass(string $modelClass, $connection = null): self
    {
        $this->_modelClass = $modelClass;

        if ($connection !== null) {
            $this->_modelConnection = $connection;
        }

        return $this;
    }

    /**
     * Get the Model class associated with this Entity, if known.
     */
    public function getModelClass(): ?string
    {
        if ($this->_modelClass !== null) {
            return $this->_modelClass;
        }

        if (property_exists($this, 'modelClass') && is_string($this->modelClass) && $this->modelClass !== '') {
            return $this->modelClass;
        }

        return null;
    }

    /**
     * Get a fresh Model instance for this Entity.
     */
    protected function newModelForRelations(): ?Model
    {
        $modelClass = $this->getModelClass();
        if ($modelClass === null) {
            return null;
        }

        if ($this->_modelConnection !== null && class_exists($modelClass)) {
            $instance = new $modelClass($this->_modelConnection);
            if ($instance instanceof Model) {
                return $instance;
            }
        }

        return Relation::createModelInstance($modelClass);
    }

    /**
     * Check whether a relationship is already loaded on this Entity.
     */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->_relations);
    }

    /**
     * Get a loaded relationship value (or null if not loaded).
     *
     * @return mixed
     */
    public function getRelation(string $name)
    {
        return $this->_relations[$name] ?? null;
    }

    /**
     * Get all loaded relationships.
     *
     * @return array<string, mixed>
     */
    public function getRelations(): array
    {
        return $this->_relations;
    }

    /**
     * Set a loaded relationship value without dirtying $attributes / hasChanged().
     *
     * @param mixed $value
     */
    public function setRelation(string $name, $value): self
    {
        $this->_relations[$name] = $value;

        return $this;
    }

    /**
     * Set all loaded relationships at once.
     *
     * @param array<string, mixed> $relations
     */
    public function setRelations(array $relations): self
    {
        $this->_relations = $relations;

        return $this;
    }

    /**
     * Unset a loaded relationship.
     */
    public function unsetRelation(string $name): self
    {
        unset($this->_relations[$name]);

        return $this;
    }

    /**
     * Lazy-load one or more relationships onto this Entity.
     *
     * @param string|array<int|string, mixed> $relations
     */
    public function load($relations): self
    {
        $model = $this->newModelForRelations();
        if ($model !== null && method_exists($model, 'load')) {
            $args = func_get_args();
            $model->load($this, ...(count($args) > 1 ? $args : [$relations]));
        }

        return $this;
    }

    /**
     * Lazy-load relationships onto this Entity only if they are not already loaded.
     *
     * @param string|array<int|string, mixed> $relations
     */
    public function loadMissing($relations): self
    {
        $model = $this->newModelForRelations();
        if ($model !== null && method_exists($model, 'loadMissing')) {
            $args = func_get_args();
            $model->loadMissing($this, ...(count($args) > 1 ? $args : [$relations]));
        }

        return $this;
    }

    /**
     * Lazy-load relationship counts onto this Entity.
     *
     * @param string|array<int|string, mixed> $relations
     */
    public function loadCount($relations): self
    {
        $model = $this->newModelForRelations();
        if ($model !== null && method_exists($model, 'loadCount')) {
            $model->loadCount($this, $relations);
        }

        return $this;
    }

    /**
     * Check if the associated model defines a relationship named $name.
     */
    protected function modelHasRelation(string $name): bool
    {
        $model = $this->newModelForRelations();
        if ($model === null || ! method_exists($model, 'hasRelation')) {
            return false;
        }

        return $model->hasRelation($name);
    }

    /**
     * Forward relationship method calls (e.g., $user->posts()) to a parent-bound Relation.
     *
     * @param array<int, mixed> $arguments
     *
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        $model = $this->newModelForRelations();
        if ($model !== null && method_exists($model, 'hasRelation') && $model->hasRelation($method)) {
            $relation = $model->resolveRelation($method);

            return $relation->for($this);
        }

        throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }

    /**
     * Retrieve an attribute or loaded/lazy-loaded relationship.
     *
     * @return mixed
     */
    public function __get(string $key)
    {
        if (array_key_exists($key, $this->_relations)) {
            return $this->_relations[$key];
        }

        // If the attribute exists as a normal column/getter, return it via parent::__get
        $dbColumn = $this->mapProperty($key);
        $method   = 'get' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $dbColumn)));
        if (
            method_exists($this, '_' . $method)
            || method_exists($this, $method)
            || array_key_exists($dbColumn, $this->attributes)
        ) {
            return parent::__get($key);
        }

        // Otherwise check if $key is a defined relationship and lazy-load it
        $model = $this->newModelForRelations();
        if ($model !== null && method_exists($model, 'hasRelation') && $model->hasRelation($key)) {
            $model->load($this, $key);

            return $this->_relations[$key] ?? null;
        }

        return parent::__get($key);
    }

    /**
     * Set an attribute, or store in $_relations if it is a known relationship.
     *
     * @param mixed $value
     */
    public function __set(string $key, $value = null)
    {
        if (array_key_exists($key, $this->_relations) || $this->modelHasRelation($key)) {
            $this->_relations[$key] = $value;

            return;
        }

        parent::__set($key, $value);
    }

    /**
     * Check whether an attribute or relationship is set.
     */
    public function __isset(string $key): bool
    {
        if (array_key_exists($key, $this->_relations)) {
            return $this->_relations[$key] !== null;
        }

        if (parent::__isset($key)) {
            return true;
        }

        $model = $this->newModelForRelations();
        if ($model !== null && method_exists($model, 'hasRelation') && $model->hasRelation($key)) {
            $model->load($this, $key);

            return isset($this->_relations[$key]);
        }

        return false;
    }

    /**
     * Unset an attribute or loaded relationship.
     */
    public function __unset(string $key): void
    {
        if (array_key_exists($key, $this->_relations)) {
            unset($this->_relations[$key]);

            return;
        }

        parent::__unset($key);
    }

    /**
     * Include loaded relationships when converting the Entity to an array or JSON.
     */
    public function toArray(bool $onlyChanged = false, bool $cast = true, bool $recursive = false): array
    {
        $data = parent::toArray($onlyChanged, $cast, $recursive);

        if ($onlyChanged) {
            return $data;
        }

        foreach ($this->_relations as $name => $value) {
            $data[$name] = $this->convertRelationToArray($value, $onlyChanged, $cast, $recursive);
        }

        return $data;
    }

    /**
     * Convert a loaded relation value (Entity, array of Entities, Pivot, etc.) to array.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function convertRelationToArray($value, bool $onlyChanged, bool $cast, bool $recursive)
    {
        if ($value instanceof \CodeIgniter\Entity\Entity) {
            return $value->toArray($onlyChanged, $cast, $recursive);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $item) {
                $out[$k] = $this->convertRelationToArray($item, $onlyChanged, $cast, $recursive);
            }

            return $out;
        }

        if (is_object($value) && is_callable([$value, 'toArray'])) {
            return $value->toArray();
        }

        return $value;
    }
}
