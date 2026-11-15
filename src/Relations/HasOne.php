<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Model;
use stdClass;

class HasOne extends HasOneOrMany
{
    /**
     * Default model/array/callback when relation is missing.
     *
     * @var bool|array<string, mixed>|callable
     */
    protected $withDefault = false;

    /**
     * Configuration for one-of-many queries.
     *
     * @var array<string, string>|null
     */
    protected ?array $ofManyColumns = null;

    /**
     * Configure a default value/instance when no related record exists.
     *
     * @param bool|array<string, mixed>|callable $callback
     */
    public function withDefault($callback = true): self
    {
        $this->withDefault = $callback;

        return $this;
    }

    /**
     * Configure this hasOne to select the "latest" or "oldest" or custom aggregate one-of-many.
     *
     * @param string|array<string, string>|null $column
     */
    public function ofMany($column = null, string $aggregate = 'max', ?callable $relation = null): self
    {
        if ($relation !== null) {
            $relation($this);
        }

        if (is_array($column)) {
            $normalized = [];
            foreach ($column as $col => $agg) {
                $normalized[(string) $col] = strtolower((string) $agg) === 'min' ? 'min' : 'max';
            }
            $this->ofManyColumns = $normalized;

            return $this;
        }

        $col                 = $column ?? static::getModelPrimaryKey($this->getRelated());
        $agg                 = strtolower($aggregate) === 'min' ? 'min' : 'max';
        $this->ofManyColumns = [$col => $agg];

        return $this;
    }

    /**
     * Retrieve the latest related record per parent.
     *
     * @param string|array<string>|null $column
     */
    public function latestOfMany($column = null): self
    {
        if (is_array($column)) {
            $cols = [];
            foreach ($column as $col) {
                $cols[(string) $col] = 'max';
            }

            return $this->ofMany($cols);
        }

        return $this->ofMany($column, 'max');
    }

    /**
     * Retrieve the oldest related record per parent.
     *
     * @param string|array<string>|null $column
     */
    public function oldestOfMany($column = null): self
    {
        if (is_array($column)) {
            $cols = [];
            foreach ($column as $col) {
                $cols[(string) $col] = 'min';
            }

            return $this->ofMany($cols);
        }

        return $this->ofMany($column, 'min');
    }

    /**
     * @param array|object $parentRow
     */
    protected function addConstraints(Model $query, $parentRow): void
    {
        parent::addConstraints($query, $parentRow);

        if ($this->ofManyColumns !== null) {
            $table = static::getModelTable($query);
            $pk    = static::getModelPrimaryKey($query);

            foreach ($this->ofManyColumns as $col => $agg) {
                $dir = $agg === 'min' ? 'ASC' : 'DESC';
                $query->orderBy($table . '.' . $col, $dir);
            }

            if (! isset($this->ofManyColumns[$pk])) {
                $lastAgg = end($this->ofManyColumns);
                $query->orderBy($table . '.' . $pk, $lastAgg === 'min' ? 'ASC' : 'DESC');
            }
        }
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
     * Eager load hasOne relation onto all parent rows.
     *
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void
    {
        $ids = static::extractUniqueKeys($parentRows, $this->localKey);

        if ($ids === []) {
            foreach ($parentRows as &$row) {
                static::setRowRelation($row, $this->relationName, $this->makeDefaultRelated($row));
            }
            unset($row);

            return;
        }

        $results = $this->ofManyColumns !== null
            ? $this->fetchOfManyResults($ids, $nestedEagerLoads)
            : $this->fetchStandardResults($ids, $nestedEagerLoads);

        $grouped = [];
        foreach ($results as $relatedRow) {
            $fk = static::getRowValue($relatedRow, $this->foreignKey);
            if ($fk === null || $fk === '') {
                continue;
            }
            $lookup = (string) $fk;
            if (! array_key_exists($lookup, $grouped)) {
                $grouped[$lookup] = $relatedRow;
            }
        }

        foreach ($parentRows as &$row) {
            $localVal = static::getRowValue($row, $this->localKey);
            $lookup   = ($localVal !== null && $localVal !== '') ? (string) $localVal : null;
            $matched  = ($lookup !== null && array_key_exists($lookup, $grouped))
                ? $grouped[$lookup]
                : $this->makeDefaultRelated($row);

            static::setRowRelation($row, $this->relationName, $matched);
        }
        unset($row);
    }

    /**
     * @param array<int, int|string> $ids
     * @param array<string, array>   $nestedEagerLoads
     *
     * @return array<int, array|object>
     */
    protected function fetchStandardResults(array $ids, array $nestedEagerLoads): array
    {
        $model = $this->newRelatedInstance();
        $table = static::getModelTable($model);

        $model->whereIn($table . '.' . $this->foreignKey, $ids);
        $this->applyPendingCalls($model, false, $nestedEagerLoads);

        return $model->findAll();
    }

    /**
     * Fetch the single winning row per parent for ofMany / latestOfMany / oldestOfMany.
     *
     * @param array<int, int|string> $ids
     * @param array<string, array>   $nestedEagerLoads
     *
     * @return array<int, array|object>
     */
    protected function fetchOfManyResults(array $ids, array $nestedEagerLoads): array
    {
        $probe = $this->newRelatedInstance();
        $table = static::getModelTable($probe);
        $pk    = static::getModelPrimaryKey($probe);

        $probe->whereIn($table . '.' . $this->foreignKey, $ids);
        $this->applyPendingCalls($probe, true);

        $selectCols = [$table . '.' . $pk, $table . '.' . $this->foreignKey];
        foreach (array_keys($this->ofManyColumns) as $col) {
            $qualified = $table . '.' . $col;
            if (! in_array($qualified, $selectCols, true)) {
                $selectCols[] = $qualified;
            }
        }

        $probe->select($selectCols)->asArray();
        $candidates = $probe->findAll();

        if ($candidates === []) {
            return [];
        }

        $winners = [];
        foreach ($candidates as $candidate) {
            $fkKey = (string) $candidate[$this->foreignKey];
            if (! isset($winners[$fkKey]) || $this->isBetterCandidate($candidate, $winners[$fkKey], $pk)) {
                $winners[$fkKey] = $candidate;
            }
        }

        $winningIds = array_values(array_map(static fn (array $row) => $row[$pk], $winners));
        if ($winningIds === []) {
            return [];
        }

        $fullModel = $this->newRelatedInstance();
        $fullTable = static::getModelTable($fullModel);
        $fullModel->whereIn($fullTable . '.' . $pk, $winningIds);

        if ($this->selectedColumns !== null && $this->selectedColumns !== []) {
            $fullModel->select($this->selectedColumns);
        }

        // Preserve any returnType overrides (asArray/asObject) or withDeleted/nested with
        foreach ($this->pendingCalls as [$method, $args]) {
            if (in_array($method, ['asArray', 'asObject', 'withDeleted', 'onlyDeleted', 'with', 'without', 'withoutRelations', 'withCount', 'withSum', 'withAvg', 'withMin', 'withMax', 'withExists'], true)) {
                $fullModel->{$method}(...$args);
            }
        }

        $this->applyNestedEagerLoads($fullModel, $nestedEagerLoads);

        return $fullModel->findAll();
    }

    /**
     * Compare two candidate rows according to $ofManyColumns + primary key tie-breaker.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    protected function isBetterCandidate(array $candidate, array $current, string $pk): bool
    {
        $lastAgg = 'max';
        foreach ($this->ofManyColumns as $col => $agg) {
            $lastAgg = $agg;
            $a       = $candidate[$col] ?? null;
            $b       = $current[$col] ?? null;

            if ($a === $b) {
                continue;
            }
            if ($a === null) {
                return false;
            }
            if ($b === null) {
                return true;
            }

            return $agg === 'min' ? ($a < $b) : ($a > $b);
        }

        if (! isset($this->ofManyColumns[$pk])) {
            $a = $candidate[$pk] ?? null;
            $b = $current[$pk] ?? null;
            if ($a !== $b) {
                return $lastAgg === 'min' ? ($a < $b) : ($a > $b);
            }
        }

        return false;
    }

    /**
     * Override existence query to enforce ofMany winning-row condition when configured.
     */
    public function getRelationExistenceQuery(
        Model $outerModel,
        string $outerTable,
        ?callable $callback = null,
        string $selectExpr = '1',
        bool $allowConstraints = true
    ): string {
        if ($this->ofManyColumns === null) {
            return parent::getRelationExistenceQuery($outerModel, $outerTable, $callback, $selectExpr, $allowConstraints);
        }

        $relatedModel = $this->newRelatedInstance();
        $relatedTable = static::getModelTable($relatedModel);
        $pk           = static::getModelPrimaryKey($relatedModel);
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

        // Apply base relation constraints before NOT EXISTS tie-breaker
        if ($allowConstraints) {
            $this->applyPendingCalls($relatedModel, true);
        }

        if (! $this->hasWithDeletedPendingCall()) {
            static::applySoftDeleteToBuilder($builder, $relatedModel, $innerAlias);
        }

        // Build correlated NOT EXISTS ensuring this row is the winning ofMany row
        $subAlias   = '__of_many';
        $subBuilder = $db->table($relatedTable . ' AS ' . $subAlias);
        $subBuilder->select('1', false);

        static::applyRawWhere(
            $subBuilder,
            $db->protectIdentifiers($subAlias . '.' . $this->foreignKey)
            . ' = '
            . $db->protectIdentifiers($innerAlias . '.' . $this->foreignKey)
        );

        if (! $this->hasWithDeletedPendingCall()) {
            static::applySoftDeleteToBuilder($subBuilder, $relatedModel, $subAlias);
        }

        // Build strict comparison across ofManyColumns + pk tie-breaker
        $cols = $this->ofManyColumns;
        if (! isset($cols[$pk])) {
            $cols[$pk] = end($cols);
        }

        $orBranches = [];
        $eqPrefix   = [];
        foreach ($cols as $col => $agg) {
            $op       = $agg === 'min' ? '<' : '>';
            $subCol   = $db->protectIdentifiers($subAlias . '.' . $col);
            $innerCol = $db->protectIdentifiers($innerAlias . '.' . $col);
            $cmp      = "{$subCol} {$op} {$innerCol}";
            if ($eqPrefix !== []) {
                $cmp = '(' . implode(' AND ', $eqPrefix) . ' AND ' . $cmp . ')';
            }
            $orBranches[] = $cmp;
            $eqPrefix[]   = "{$subCol} = {$innerCol}";
        }

        static::applyRawWhere($subBuilder, '(' . implode(' OR ', $orBranches) . ')');

        static::applyRawWhere($builder, 'NOT EXISTS (' . $subBuilder->getCompiledSelect() . ')');

        if ($callback !== null) {
            $callback($this->makeLiveQueryWrapper($relatedModel));
        }

        return $builder->getCompiledSelect();
    }

    /**
     * Build a default value when no related record is matched.
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

        $attributes = [];
        if ($parentRow !== null) {
            $parentId = static::getRowValue($parentRow, $this->localKey);
            if ($parentId !== null) {
                $attributes[$this->foreignKey] = $parentId;
            }
        }

        if (is_array($this->withDefault)) {
            $attributes = array_merge($attributes, $this->withDefault);
        }

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
