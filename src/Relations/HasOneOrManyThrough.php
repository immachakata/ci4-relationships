<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Model;

abstract class HasOneOrManyThrough extends Relation
{
    protected string $throughClass;
    protected string $firstKey;
    protected string $secondKey;
    protected string $localKey;
    protected string $secondLocalKey;
    protected bool $withDeletedParents = false;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $throughClass,
        string $firstKey,
        string $secondKey,
        string $localKey,
        string $secondLocalKey,
        string $relationName = ''
    ) {
        parent::__construct($parent, $relatedClass, $relationName);

        $this->throughClass   = $throughClass;
        $this->firstKey       = $firstKey;
        $this->secondKey      = $secondKey;
        $this->localKey       = $localKey;
        $this->secondLocalKey = $secondLocalKey;
    }

    /**
     * Include soft-deleted intermediate (through) records.
     */
    public function withDeletedParents(bool $val = true): self
    {
        $this->withDeletedParents = $val;

        return $this;
    }

    protected function newThroughInstance(): Model
    {
        return static::createModelInstance($this->throughClass, $this->parent);
    }

    protected function joinThroughTable(Model $query, Model $throughModel, ?string $relatedAlias = null): void
    {
        $relatedTable = $relatedAlias ?? static::getModelTable($query);
        $throughTable = static::getModelTable($throughModel);

        $query->join(
            $throughTable,
            $throughTable . '.' . $this->secondLocalKey . ' = ' . $relatedTable . '.' . $this->secondKey,
            'inner'
        );

        if (! $this->withDeletedParents && static::modelUsesSoftDeletes($throughModel)) {
            $query->where($throughTable . '.' . static::getModelDeletedField($throughModel), null);
        }
    }

    protected function applySelectWithThroughKey(Model $query, Model $throughModel): void
    {
        $relatedTable  = static::getModelTable($query);
        $throughTable  = static::getModelTable($throughModel);
        $hasUserSelect = false;

        if ($this->selectedColumns !== null && $this->selectedColumns !== []) {
            $qualified = array_map(static function (string $col) use ($relatedTable): string {
                $col = trim($col);

                return strpos($col, '.') === false ? $relatedTable . '.' . $col : $col;
            }, $this->selectedColumns);
            $query->select($qualified);
            $hasUserSelect = true;
        } else {
            foreach ($this->pendingCalls as [$method, $args]) {
                if ($method === 'select') {
                    $hasUserSelect = true;
                    break;
                }
            }
        }

        if (! $hasUserSelect) {
            $query->select($relatedTable . '.*');
        }

        $query->select($throughTable . '.' . $this->firstKey . ' AS __through_parent_key');
    }

    /**
     * @param array|object $parentRow
     */
    protected function addConstraints(Model $query, $parentRow): void
    {
        $throughModel = $this->newThroughInstance();
        $throughTable = static::getModelTable($throughModel);

        $this->joinThroughTable($query, $throughModel);

        $localVal = static::getRowValue($parentRow, $this->localKey);
        if ($localVal === null || $localVal === '') {
            $query->where('1 = 0', null, false);

            return;
        }

        $query->where($throughTable . '.' . $this->firstKey, $localVal);
    }

    public function toQuery(): Model
    {
        if ($this->parentRow === null) {
            throw new \LogicException(sprintf(
                'Cannot execute relation [%s] query without a parent row. Use ->for($parent) or access via an Entity.',
                $this->relationName ?: static::class
            ));
        }

        $query        = $this->newRelatedInstance();
        $throughModel = $this->newThroughInstance();
        $this->addConstraints($query, $this->parentRow);
        $this->applySelectWithThroughKey($query, $throughModel);
        $this->applyPendingCalls($query);

        return $query;
    }

    /**
     * @return array<int, array|object>
     */
    public function findAll(?int $limit = null, int $offset = 0): array
    {
        $results = parent::findAll($limit, $offset);
        foreach ($results as &$row) {
            static::unsetRowKey($row, '__through_parent_key');
        }
        unset($row);

        return $results;
    }

    /**
     * @return array|object|null
     */
    public function first()
    {
        $row = parent::first();
        if ($row !== null) {
            static::unsetRowKey($row, '__through_parent_key');
        }

        return $row;
    }

    public function getRelationExistenceQuery(
        Model $outerModel,
        string $outerTable,
        ?callable $callback = null,
        string $selectExpr = '1',
        bool $allowConstraints = true
    ): string {
        $relatedModel = $this->newRelatedInstance();
        $throughModel = $this->newThroughInstance();
        $relatedTable = static::getModelTable($relatedModel);
        $throughTable = static::getModelTable($throughModel);
        $db           = static::getModelConnection($relatedModel);

        $isSelf     = ($relatedTable === $outerTable);
        $innerAlias = $isSelf ? '__rel_self' : $relatedTable;
        $tableExpr  = $isSelf ? ($relatedTable . ' AS ' . $innerAlias) : $relatedTable;

        $builder = $db->table($tableExpr);
        static::setObjectProperty($relatedModel, 'builder', $builder);

        $builder->select($selectExpr, false);

        $this->joinThroughTable($relatedModel, $throughModel, $innerAlias);

        static::applyRawWhere(
            $builder,
            $db->protectIdentifiers($throughTable . '.' . $this->firstKey)
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
        $throughModel = $this->newThroughInstance();
        $relatedTable = static::getModelTable($relatedModel);
        $throughTable = static::getModelTable($throughModel);
        $db           = static::getModelConnection($relatedModel);

        $this->joinThroughTable($relatedModel, $throughModel);
        $this->applyPendingCalls($relatedModel, true);
        if ($callback !== null) {
            $callback($this->makeLiveQueryWrapper($relatedModel));
        }

        $builder = $relatedModel->builder();
        if (! $this->hasWithDeletedPendingCall()) {
            static::applySoftDeleteToBuilder($builder, $relatedModel);
        }

        $fkQualified = $throughTable . '.' . $this->firstKey;
        $builder->whereIn($fkQualified, $ids);

        if ($fn === 'EXISTS') {
            $builder->select($db->protectIdentifiers($fkQualified) . ' AS __agg_key', false);
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
