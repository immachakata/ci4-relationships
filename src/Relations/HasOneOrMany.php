<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Entity\Entity;
use CodeIgniter\Model;

abstract class HasOneOrMany extends Relation
{
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $foreignKey,
        string $localKey,
        string $relationName = ''
    ) {
        parent::__construct($parent, $relatedClass, $relationName);

        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    /**
     * @param array|object $parentRow
     */
    protected function addConstraints(Model $query, $parentRow): void
    {
        $localVal = static::getRowValue($parentRow, $this->localKey);

        if ($localVal === null || $localVal === '') {
            // Force impossible match when parent key is null/empty
            $query->where('1 = 0', null, false);

            return;
        }

        $table = static::getModelTable($query);
        $query->where($table . '.' . $this->foreignKey, $localVal);
    }

    /**
     * Attach and save an existing or new model/array/Entity on this relation.
     *
     * @param array|object $record
     *
     * @return bool
     */
    public function save($record): bool
    {
        if ($this->parentRow === null) {
            throw new \LogicException('Cannot save related record without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        $parentId = static::getRowValue($this->parentRow, $this->localKey);
        static::setRowValue($record, $this->foreignKey, $parentId);

        return (bool) $this->newRelatedInstance()->save($record);
    }

    /**
     * Save multiple related records.
     *
     * @param array<int, array|object> $records
     */
    public function saveMany(array $records): bool
    {
        foreach ($records as $record) {
            if (! $this->save($record)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create and persist a new related record.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array|object|false
     */
    public function create(array $attributes = [])
    {
        if ($this->parentRow === null) {
            throw new \LogicException('Cannot create related record without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        $parentId                     = static::getRowValue($this->parentRow, $this->localKey);
        $attributes[$this->foreignKey] = $parentId;

        $model      = $this->newRelatedInstance();
        $returnType = static::getModelReturnType($model);
        $pk         = static::getModelPrimaryKey($model);

        $insertData = $attributes;
        if ($returnType !== 'array' && $returnType !== 'object' && class_exists($returnType)) {
            $entity = new $returnType($attributes);
            if ($entity instanceof Entity) {
                $insertData = $entity;
            }
        }

        $insertId = $model->insert($insertData, true);
        if ($insertId === false) {
            return false;
        }

        $lookupId = $attributes[$pk] ?? $insertId;
        if ($lookupId !== null && $lookupId !== 0 && $lookupId !== '') {
            $found = $this->newRelatedInstance()->find($lookupId);
            if ($found !== null) {
                return $found;
            }
        }

        return $insertData;
    }

    /**
     * Create and persist multiple related records.
     *
     * @param array<int, array<string, mixed>> $records
     *
     * @return array<int, array|object|false>
     */
    public function createMany(array $records): array
    {
        $created = [];
        foreach ($records as $attributes) {
            $created[] = $this->create($attributes);
        }

        return $created;
    }

    /**
     * Build existence/aggregate SQL subquery for has / whereHas / withCount / withSum / etc.
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
            $db->protectIdentifiers($innerAlias . '.' . $this->foreignKey)
            . ' = '
            . $db->protectIdentifiers($outerTable . '.' . $this->localKey)
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
        $ids = static::extractUniqueKeys($parentRows, $this->localKey);
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

        $fkQualified = $relatedTable . '.' . $this->foreignKey;
        $builder->whereIn($fkQualified, $ids);

        if ($fn === 'EXISTS') {
            $builder->select($fkQualified . ' AS __agg_key', false);
            $builder->groupBy($fkQualified);
            $rows = $builder->get()->getResultArray();
            $map  = [];
            foreach ($rows as $r) {
                $map[(string) $r['__agg_key']] = true;
            }

            foreach ($parentRows as &$row) {
                $key = static::getRowValue($row, $this->localKey);
                $val = ($key !== null && $key !== '' && isset($map[(string) $key]));
                static::setRowRelation($row, $alias, $val);
            }
            unset($row);

            return;
        }

        $aggCol = $column === '*'
            ? '*'
            : (strpos($column, '.') === false ? $db->protectIdentifiers($relatedTable . '.' . $column) : $column);

        $builder->select($db->protectIdentifiers($fkQualified) . ' AS __agg_key', false);
        $builder->select(sprintf('%s(%s) AS __agg_val', $fn, $aggCol), false);
        $builder->groupBy($fkQualified);

        $rows = $builder->get()->getResultArray();
        $map  = [];
        foreach ($rows as $r) {
            $map[(string) $r['__agg_key']] = $r['__agg_val'];
        }

        $default = $fn === 'COUNT' ? 0 : null;

        foreach ($parentRows as &$row) {
            $key = static::getRowValue($row, $this->localKey);
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
}
