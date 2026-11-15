<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Model;
use stdClass;

class BelongsTo extends Relation
{
    protected string $foreignKey;
    protected string $ownerKey;

    /**
     * Default model/array/callback when relation is missing.
     *
     * @var bool|array<string, mixed>|callable
     */
    protected $withDefault = false;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $ownerKey,
        string $relationName = ''
    ) {
        parent::__construct($parent, $relatedClass, $relationName);

        $this->foreignKey = $foreignKey;
        $this->ownerKey   = $ownerKey;
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    public function getOwnerKeyName(): string
    {
        return $this->ownerKey;
    }

    /**
     * Configure a default value/instance when no owner record exists.
     *
     * @param bool|array<string, mixed>|callable $callback
     */
    public function withDefault($callback = true): self
    {
        $this->withDefault = $callback;

        return $this;
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

        $table = static::getModelTable($query);
        $query->where($table . '.' . $this->ownerKey, $fkVal);
    }

    /**
     * @return array|object|null
     */
    public function first()
    {
        $result = parent::first();

        if ($result === null && $this->withDefault !== false) {
            return $this->makeDefaultRelated($this->parentRow);
        }

        return $result;
    }

    /**
     * Associate an owner model/array/id with the bound parent row.
     *
     * @param array|object|int|string|null $model
     *
     * @return array|object
     */
    public function associate($model)
    {
        if ($this->parentRow === null) {
            throw new \LogicException('Cannot associate without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        $ownerId = (is_array($model) || is_object($model))
            ? static::getRowValue($model, $this->ownerKey)
            : $model;

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
     * Dissociate the owner from the bound parent row (sets foreign key to null).
     *
     * @return array|object
     */
    public function dissociate()
    {
        if ($this->parentRow === null) {
            throw new \LogicException('Cannot dissociate without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        static::setRowValue($this->parentRow, $this->foreignKey, null);

        if (is_object($this->parentRow) && method_exists($this->parentRow, 'setRelation')) {
            $this->parentRow->setRelation($this->relationName, null);
        }

        return $this->parentRow;
    }

    /**
     * Eager load belongsTo relation onto all parent rows.
     *
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void
    {
        $ids = static::extractUniqueKeys($parentRows, $this->foreignKey);

        if ($ids === []) {
            foreach ($parentRows as &$row) {
                static::setRowRelation($row, $this->relationName, $this->makeDefaultRelated($row));
            }
            unset($row);

            return;
        }

        $model = $this->newRelatedInstance();
        $table = static::getModelTable($model);

        $model->whereIn($table . '.' . $this->ownerKey, $ids);
        $this->applyPendingCalls($model, false, $nestedEagerLoads);

        $results = $model->findAll();

        $dictionary = [];
        foreach ($results as $relatedRow) {
            $ok = static::getRowValue($relatedRow, $this->ownerKey);
            if ($ok === null || $ok === '') {
                continue;
            }
            $dictionary[(string) $ok] = $relatedRow;
        }

        foreach ($parentRows as &$row) {
            $fkVal   = static::getRowValue($row, $this->foreignKey);
            $lookup  = ($fkVal !== null && $fkVal !== '') ? (string) $fkVal : null;
            $matched = ($lookup !== null && array_key_exists($lookup, $dictionary))
                ? $dictionary[$lookup]
                : $this->makeDefaultRelated($row);

            static::setRowRelation($row, $this->relationName, $matched);
        }
        unset($row);
    }

    /**
     * Build existence/aggregate SQL subquery for belongsTo.
     */
    public function getRelationExistenceQuery(
        Model $outerModel,
        string $outerTable,
        ?callable $callback = null,
        string $selectExpr = '1',
        bool $allowConstraints = true
    ): string {
        $relatedModel = $this->newRelatedInstance();
        $relatedTable = static::getModelTable($relatedModel);
        $db           = static::getModelConnection($relatedModel);

        $isSelf     = ($relatedTable === $outerTable);
        $innerAlias = $isSelf ? '__rel_self' : $relatedTable;
        $tableExpr  = $isSelf ? ($relatedTable . ' AS ' . $innerAlias) : $relatedTable;

        $builder = $db->table($tableExpr);
        static::setObjectProperty($relatedModel, 'builder', $builder);

        $builder->select($selectExpr, false);

        static::applyRawWhere(
            $builder,
            $db->protectIdentifiers($innerAlias . '.' . $this->ownerKey)
            . ' = '
            . $db->protectIdentifiers($outerTable . '.' . $this->foreignKey)
        );

        if ($allowConstraints) {
            $this->applyPendingCalls($relatedModel, true);
        }

        if (! $this->hasWithDeletedPendingCall()) {
            static::applySoftDeleteToBuilder($builder, $relatedModel, $innerAlias);
        }

        if ($callback !== null) {
            $callback($this->makeLiveQueryWrapper($relatedModel));
        }

        return $builder->getCompiledSelect();
    }

    /**
     * Lazy-load an aggregate onto already-fetched parent rows.
     *
     * @param array<int, array|object> $parentRows
     */
    public function loadAggregate(
        array &$parentRows,
        string $alias,
        string $function,
        string $column,
        ?callable $callback = null
    ): void {
        $ids = static::extractUniqueKeys($parentRows, $this->foreignKey);
        $fn  = strtoupper($function);

        if ($ids === []) {
            $default = $fn === 'COUNT' ? 0 : ($fn === 'EXISTS' ? false : null);
            foreach ($parentRows as &$row) {
                static::setRowRelation($row, $alias, $default);
            }
            unset($row);

            return;
        }

        $relatedModel = $this->newRelatedInstance();
        $relatedTable = static::getModelTable($relatedModel);
        $db           = static::getModelConnection($relatedModel);

        $this->applyPendingCalls($relatedModel, true);
        if ($callback !== null) {
            $callback($this->makeLiveQueryWrapper($relatedModel));
        }

        $builder = $relatedModel->builder();
        if (! $this->hasWithDeletedPendingCall()) {
            static::applySoftDeleteToBuilder($builder, $relatedModel);
        }

        $okQualified = $relatedTable . '.' . $this->ownerKey;
        $builder->whereIn($okQualified, $ids);

        if ($fn === 'EXISTS') {
            $builder->select($okQualified . ' AS __agg_key', false);
            $builder->groupBy($okQualified);
            $rows = $builder->get()->getResultArray();
            $map  = [];
            foreach ($rows as $r) {
                $map[(string) $r['__agg_key']] = true;
            }

            foreach ($parentRows as &$row) {
                $key = static::getRowValue($row, $this->foreignKey);
                $val = ($key !== null && $key !== '' && isset($map[(string) $key]));
                static::setRowRelation($row, $alias, $val);
            }
            unset($row);

            return;
        }

        $aggCol = $column === '*'
            ? '*'
            : (strpos($column, '.') === false ? $db->protectIdentifiers($relatedTable . '.' . $column) : $column);

        $builder->select($db->protectIdentifiers($okQualified) . ' AS __agg_key', false);
        $builder->select(sprintf('%s(%s) AS __agg_val', $fn, $aggCol), false);
        $builder->groupBy($okQualified);

        $rows = $builder->get()->getResultArray();
        $map  = [];
        foreach ($rows as $r) {
            $map[(string) $r['__agg_key']] = $r['__agg_val'];
        }

        $default = $fn === 'COUNT' ? 0 : null;

        foreach ($parentRows as &$row) {
            $key = static::getRowValue($row, $this->foreignKey);
            $val = ($key !== null && $key !== '' && array_key_exists((string) $key, $map))
                ? $map[(string) $key]
                : $default;

            if ($fn === 'COUNT') {
                $val = (int) $val;
            } elseif ($fn === 'AVG' && $val !== null) {
                $val = (float) $val;
            } elseif ($fn === 'SUM' && $val !== null && is_numeric($val)) {
                $val = strpos((string) $val, '.') !== false ? (float) $val : (int) $val;
            }

            static::setRowRelation($row, $alias, $val);
        }
        unset($row);
    }

    /**
     * Build a default value when no owner record is matched.
     *
     * @param array|object|null $parentRow
     *
     * @return array|object|null
     */
    protected function makeDefaultRelated($parentRow)
    {
        if ($this->withDefault === false) {
            return null;
        }

        $model      = $this->newRelatedInstance();
        $returnType = static::getModelReturnType($model);
        $attributes = is_array($this->withDefault) ? $this->withDefault : [];

        if ($returnType === 'array') {
            $instance = $attributes;
        } elseif ($returnType === 'object') {
            $instance = new stdClass();
            foreach ($attributes as $k => $v) {
                $instance->{$k} = $v;
            }
        } elseif (class_exists($returnType)) {
            $instance = new $returnType($attributes);
        } else {
            $instance = $attributes;
        }

        if (is_callable($this->withDefault)) {
            $result = ($this->withDefault)($instance, $parentRow);
            if ($result !== null) {
                return $result;
            }
        }

        return $instance;
    }
}
