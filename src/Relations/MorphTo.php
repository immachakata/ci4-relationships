<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use BadMethodCallException;
use CodeIgniter\Model;
use LogicException;

class MorphTo extends Relation
{
    protected string $morphType;
    protected string $foreignKey;
    protected ?string $ownerKey;

    /**
     * Per-morph-type nested eager loads: [ModelClass|MorphAlias => relations].
     *
     * @var array<string, array|string>
     */
    protected array $morphWith = [];

    public function __construct(
        Model $parent,
        string $foreignKey,
        string $morphType,
        ?string $ownerKey = null,
        string $relationName = ''
    ) {
        parent::__construct($parent, '', $relationName);

        $this->foreignKey = $foreignKey;
        $this->morphType  = $morphType;
        $this->ownerKey   = $ownerKey;
    }

    public function getMorphType(): string
    {
        return $this->morphType;
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Specify nested relationships to eager load for each possible morph target type.
     *
     * @param array<string, array|string> $with
     */
    public function morphWith(array $with): self
    {
        $this->morphWith = array_merge($this->morphWith, $with);

        return $this;
    }

    /**
     * Resolve target model class from a stored morph type value.
     */
    protected function resolveMorphModelClass(string $type): string
    {
        return static::getMorphedModel($type) ?? $type;
    }

    /**
     * @param array|object $parentRow
     */
    protected function addConstraints(Model $query, $parentRow): void
    {
        $fkVal = static::getRowValue($parentRow, $this->foreignKey);
        if ($fkVal === null || $fkVal === '') {
            $query->where('1 = 0', null, false);

            return;
        }

        $ownerKey = $this->ownerKey ?? static::getModelPrimaryKey($query);
        $table    = static::getModelTable($query);
        $query->where($table . '.' . $ownerKey, $fkVal);
    }

    public function newRelatedInstance(): Model
    {
        if ($this->parentRow !== null) {
            $type = static::getRowValue($this->parentRow, $this->morphType);
            if ($type !== null && $type !== '') {
                return static::createModelInstance($this->resolveMorphModelClass((string) $type), $this->parent);
            }
        }

        throw new LogicException(sprintf(
            'MorphTo relation [%s] requires a parent row with a valid [%s] value.',
            $this->relationName,
            $this->morphType
        ));
    }

    /**
     * @return array|object|null
     */
    public function first()
    {
        if ($this->parentRow === null) {
            throw new LogicException(sprintf(
                'Cannot execute MorphTo relation [%s] without a parent row.',
                $this->relationName
            ));
        }

        $type = static::getRowValue($this->parentRow, $this->morphType);
        $id   = static::getRowValue($this->parentRow, $this->foreignKey);

        if ($type === null || $type === '' || $id === null || $id === '') {
            return null;
        }

        return parent::first();
    }

    /**
     * Associate a parent morphable record (Entity/object/Model+id) with the bound row.
     *
     * @param array|object $model
     *
     * @return array|object
     */
    public function associate($model, ?string $morphModelClass = null)
    {
        if ($this->parentRow === null) {
            throw new LogicException('Cannot associate without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        if ($morphModelClass === null && is_object($model) && method_exists($model, 'getModelClass')) {
            $morphModelClass = $model->getModelClass();
        }

        if ($morphModelClass === null) {
            throw new \InvalidArgumentException(
                'MorphTo::associate() requires the target Model class as the second argument (unless the Entity uses EntityRelationshipsTrait).'
            );
        }

        $targetModel = static::createModelInstance($morphModelClass, $this->parent);
        $ownerKey    = $this->ownerKey ?? static::getModelPrimaryKey($targetModel);
        $ownerId     = (is_array($model) || is_object($model))
            ? static::getRowValue($model, $ownerKey)
            : $model;

        $alias = static::getMorphAlias($morphModelClass);

        static::setRowValue($this->parentRow, $this->morphType, $alias);
        static::setRowValue($this->parentRow, $this->foreignKey, $ownerId);

        if (is_object($this->parentRow) && method_exists($this->parentRow, 'setRelation')) {
            if (is_array($model) || is_object($model)) {
                $this->parentRow->setRelation($this->relationName, $model);
            } else {
                $this->parentRow->unsetRelation($this->relationName);
            }
        }

        return $this->parentRow;
    }

    /**
     * @return array|object
     */
    public function dissociate()
    {
        if ($this->parentRow === null) {
            throw new LogicException('Cannot dissociate without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        static::setRowValue($this->parentRow, $this->morphType, null);
        static::setRowValue($this->parentRow, $this->foreignKey, null);

        if (is_object($this->parentRow) && method_exists($this->parentRow, 'setRelation')) {
            $this->parentRow->setRelation($this->relationName, null);
        }

        return $this->parentRow;
    }

    /**
     * Eager load morphTo relation by grouping parent rows by morphType and querying each target model once.
     *
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void
    {
        $groupedIds = [];

        foreach ($parentRows as $row) {
            $type = static::getRowValue($row, $this->morphType);
            $id   = static::getRowValue($row, $this->foreignKey);

            if ($type === null || $type === '' || $id === null || $id === '') {
                continue;
            }

            $typeStr                           = (string) $type;
            $groupedIds[$typeStr][(string) $id] = $id;
        }

        $dictionary = [];

        foreach ($groupedIds as $type => $idMap) {
            $modelClass  = $this->resolveMorphModelClass($type);
            $targetModel = static::createModelInstance($modelClass, $this->parent);
            $targetTable = static::getModelTable($targetModel);
            $ownerKey    = $this->ownerKey ?? static::getModelPrimaryKey($targetModel);

            $targetModel->whereIn($targetTable . '.' . $ownerKey, array_values($idMap));
            $this->applyPendingCalls($targetModel, false, $nestedEagerLoads);

            $extraWith = $this->morphWith[$modelClass] ?? ($this->morphWith[$type] ?? null);
            if ($extraWith !== null && method_exists($targetModel, 'with')) {
                $targetModel->with($extraWith);
            }

            $results = $targetModel->findAll();
            foreach ($results as $result) {
                $keyVal = static::getRowValue($result, $ownerKey);
                if ($keyVal !== null && $keyVal !== '') {
                    $dictionary[$type][(string) $keyVal] = $result;
                }
            }
        }

        foreach ($parentRows as &$row) {
            $type = static::getRowValue($row, $this->morphType);
            $id   = static::getRowValue($row, $this->foreignKey);

            if ($type === null || $type === '' || $id === null || $id === '') {
                static::setRowRelation($row, $this->relationName, null);
                continue;
            }

            $matched = $dictionary[(string) $type][(string) $id] ?? null;
            static::setRowRelation($row, $this->relationName, $matched);
        }
        unset($row);
    }

    public function getRelationExistenceQuery(
        Model $outerModel,
        string $outerTable,
        ?callable $callback = null,
        string $selectExpr = '1',
        bool $allowConstraints = true
    ): string {
        throw new BadMethodCallException('Existence queries (has/whereHas) are not supported on MorphTo relations.');
    }

    public function loadAggregate(
        array &$parentRows,
        string $alias,
        string $function,
        string $column,
        ?callable $callback = null
    ): void {
        throw new BadMethodCallException('Aggregate queries (withCount/loadCount) are not supported on MorphTo relations.');
    }
}
