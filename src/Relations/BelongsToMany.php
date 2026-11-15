<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use InvalidArgumentException;
use LogicException;

class BelongsToMany extends Relation
{
    protected string $table;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected string $parentKey;
    protected string $relatedKey;

    protected string $pivotAccessor = 'pivot';
    protected ?string $pivotClass   = null;

    /**
     * Extra pivot columns to retrieve.
     *
     * @var array<int, string>
     */
    protected array $pivotColumns = [];

    /**
     * Pivot where clauses: [method, args].
     *
     * @var array<int, array{0: string, 1: array}>
     */
    protected array $pivotWheres = [];

    /**
     * Pivot orderBy clauses: [column, direction].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    protected array $pivotOrderBys = [];

    protected bool $pivotWantsTimestamps = false;
    protected string $pivotCreatedAt      = 'created_at';
    protected string $pivotUpdatedAt      = 'updated_at';

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        string $relationName = ''
    ) {
        parent::__construct($parent, $relatedClass, $relationName);

        $this->table           = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey       = $parentKey;
        $this->relatedKey      = $relatedKey;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    public function getParentKeyName(): string
    {
        return $this->parentKey;
    }

    public function getRelatedKeyName(): string
    {
        return $this->relatedKey;
    }

    /**
     * Specify extra pivot columns to fetch.
     *
     * @param string|array<int, string> $columns
     */
    public function withPivot($columns): self
    {
        $cols = is_array($columns) ? $columns : func_get_args();
        foreach ($cols as $col) {
            if (! in_array($col, $this->pivotColumns, true)) {
                $this->pivotColumns[] = $col;
            }
        }

        return $this;
    }

    /**
     * Automatically maintain and fetch created_at/updated_at timestamps on the pivot table.
     */
    public function withTimestamps(string $createdAt = 'created_at', string $updatedAt = 'updated_at'): self
    {
        $this->pivotWantsTimestamps = true;
        $this->pivotCreatedAt       = $createdAt;
        $this->pivotUpdatedAt       = $updatedAt;

        return $this->withPivot([$createdAt, $updatedAt]);
    }

    /**
     * Customize the pivot attribute name (default: "pivot").
     */
    public function as(string $accessor): self
    {
        $this->pivotAccessor = $accessor;

        return $this;
    }

    /**
     * Specify a custom Pivot Entity class.
     */
    public function using(string $pivotClass): self
    {
        $this->pivotClass = $pivotClass;

        return $this;
    }

    /**
     * @param mixed $operator
     * @param mixed $value
     */
    public function wherePivot(string $column, $operator = null, $value = null, string $boolean = 'AND'): self
    {
        [$op, $val] = $this->normalizePivotOperatorAndValue($operator, $value, func_num_args() === 2);
        $method     = strtoupper($boolean) === 'OR' ? 'orWhere' : 'where';
        $this->pivotWheres[] = [$method, [$this->qualifyPivotColumn($column) . $op, $val]];

        return $this;
    }

    /**
     * @param mixed $operator
     * @param mixed $value
     */
    public function orWherePivot(string $column, $operator = null, $value = null): self
    {
        return $this->wherePivot($column, $operator, $value, 'OR');
    }

    /**
     * @param array<int, mixed> $values
     */
    public function wherePivotIn(string $column, array $values): self
    {
        $this->pivotWheres[] = ['whereIn', [$this->qualifyPivotColumn($column), $values]];

        return $this;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function wherePivotNotIn(string $column, array $values): self
    {
        $this->pivotWheres[] = ['whereNotIn', [$this->qualifyPivotColumn($column), $values]];

        return $this;
    }

    public function wherePivotNull(string $column): self
    {
        $this->pivotWheres[] = ['where', [$this->qualifyPivotColumn($column), null]];

        return $this;
    }

    public function wherePivotNotNull(string $column): self
    {
        $this->pivotWheres[] = ['where', [$this->qualifyPivotColumn($column) . ' IS NOT NULL', null, false]];

        return $this;
    }

    public function orderByPivot(string $column, string $direction = 'asc'): self
    {
        $this->pivotOrderBys[] = [$this->qualifyPivotColumn($column), $direction];

        return $this;
    }

    /**
     * @param mixed $operator
     * @param mixed $value
     *
     * @return array{0: string, 1: mixed}
     */
    protected function normalizePivotOperatorAndValue($operator, $value, bool $twoArgs): array
    {
        if ($twoArgs) {
            return ['', $operator];
        }

        if ($operator === '=' || $operator === null) {
            return ['', $value];
        }

        return [' ' . trim((string) $operator), $value];
    }

    protected function qualifyPivotColumn(string $column): string
    {
        if (strpos($column, '.') !== false) {
            return $column;
        }

        return $this->table . '.' . $column;
    }

    protected function joinPivotTable(Model $query, ?string $relatedTableAlias = null): void
    {
        $relatedTable = $relatedTableAlias ?? static::getModelTable($query);

        $query->join(
            $this->table,
            $this->table . '.' . $this->relatedPivotKey . ' = ' . $relatedTable . '.' . $this->relatedKey,
            'inner'
        );
    }

    protected function applyPivotClauses(Model $query, bool $forSubquery = false): void
    {
        foreach ($this->pivotWheres as [$method, $args]) {
            $query->{$method}(...$args);
        }

        if (! $forSubquery) {
            foreach ($this->pivotOrderBys as [$col, $dir]) {
                $query->orderBy($col, $dir);
            }
        }
    }

    protected function applySelectWithPivot(Model $query): void
    {
        $relatedTable = static::getModelTable($query);
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

        $pivotSelects = [
            $this->table . '.' . $this->foreignPivotKey . ' AS __pivot_' . $this->foreignPivotKey,
            $this->table . '.' . $this->relatedPivotKey . ' AS __pivot_' . $this->relatedPivotKey,
        ];

        foreach ($this->pivotColumns as $col) {
            if ($col === $this->foreignPivotKey || $col === $this->relatedPivotKey) {
                continue;
            }
            $pivotSelects[] = $this->table . '.' . $col . ' AS __pivot_' . $col;
        }

        $query->select($pivotSelects);
    }

    /**
     * @param array|object $parentRow
     */
    protected function addConstraints(Model $query, $parentRow): void
    {
        $parentId = static::getRowValue($parentRow, $this->parentKey);

        $this->joinPivotTable($query);
        $this->applyPivotClauses($query);

        if ($parentId === null || $parentId === '') {
            $query->where('1 = 0', null, false);

            return;
        }

        $query->where($this->table . '.' . $this->foreignPivotKey, $parentId);
    }

    public function toQuery(): Model
    {
        if ($this->parentRow === null) {
            throw new LogicException(sprintf(
                'Cannot execute relation [%s] query without a parent row. Use ->for($parent) or access via an Entity.',
                $this->relationName ?: static::class
            ));
        }

        $query = $this->newRelatedInstance();
        $this->addConstraints($query, $this->parentRow);
        $this->applySelectWithPivot($query);
        $this->applyPendingCalls($query);

        return $query;
    }

    /**
     * @return array<int, array|object>
     */
    public function findAll(?int $limit = null, int $offset = 0): array
    {
        $results = parent::findAll($limit, $offset);

        return $this->hydratePivotOnResults($results);
    }

    /**
     * @return array|object|null
     */
    public function first()
    {
        $row = parent::first();
        if ($row === null) {
            return null;
        }

        $hydrated = $this->hydratePivotOnResults([$row]);

        return $hydrated[0];
    }

    /**
     * @param array|int|string|null $id
     *
     * @return array|object|null
     */
    public function find($id = null)
    {
        $result = parent::find($id);
        if ($result === null || $result === []) {
            return $result;
        }

        $isSingle = is_numeric($id) || is_string($id);
        if ($isSingle) {
            $hydrated = $this->hydratePivotOnResults([$result]);

            return $hydrated[0];
        }

        return $this->hydratePivotOnResults($result);
    }

    public function count(bool $reset = true): int
    {
        if ($this->parentRow === null) {
            throw new LogicException('Cannot count relation without a parent row. Use ->for($parent).');
        }

        $query = $this->newRelatedInstance();
        $this->addConstraints($query, $this->parentRow);
        $this->applyPendingCalls($query, true);

        return (int) $query->countAllResults($reset);
    }

    /**
     * Eager load belongsToMany relation onto all parent rows.
     *
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void
    {
        $ids = static::extractUniqueKeys($parentRows, $this->parentKey);

        if ($ids === []) {
            foreach ($parentRows as &$row) {
                static::setRowRelation($row, $this->relationName, []);
            }
            unset($row);

            return;
        }

        $model = $this->newRelatedInstance();

        $this->joinPivotTable($model);
        $this->applyPivotClauses($model);
        $model->whereIn($this->table . '.' . $this->foreignPivotKey, $ids);
        $this->applySelectWithPivot($model);
        $this->applyPendingCalls($model, false, $nestedEagerLoads);

        $results = $model->findAll();
        $grouped = [];

        foreach ($results as $relatedRow) {
            $pivotData = $this->extractAndCleanPivotData($relatedRow);
            $parentFk  = $pivotData[$this->foreignPivotKey] ?? null;

            if ($parentFk === null || $parentFk === '') {
                continue;
            }

            $this->attachPivotToRow($relatedRow, $pivotData);
            $grouped[(string) $parentFk][] = $relatedRow;
        }

        foreach ($parentRows as &$row) {
            $parentVal = static::getRowValue($row, $this->parentKey);
            $lookup    = ($parentVal !== null && $parentVal !== '') ? (string) $parentVal : null;
            $matched   = ($lookup !== null && array_key_exists($lookup, $grouped))
                ? $grouped[$lookup]
                : [];

            static::setRowRelation($row, $this->relationName, $matched);
        }
        unset($row);
    }

    /**
     * Strip __pivot_* columns from a fetched related row and return them as an array.
     *
     * @param array|object $row
     *
     * @return array<string, mixed>
     */
    protected function extractAndCleanPivotData(&$row): array
    {
        $keys = array_unique(array_merge(
            [$this->foreignPivotKey, $this->relatedPivotKey],
            $this->pivotColumns
        ));

        $pivotData = [];
        foreach ($keys as $col) {
            $aliasKey = '__pivot_' . $col;
            $val      = static::getRowValue($row, $aliasKey);
            if ($val !== null || is_array($row) && array_key_exists($aliasKey, $row) || is_object($row)) {
                $pivotData[$col] = $val;
            }
            static::unsetRowKey($row, $aliasKey);
        }

        return $pivotData;
    }

    /**
     * Attach formatted pivot data (array or Pivot Entity) onto a related row.
     *
     * @param array|object         $row
     * @param array<string, mixed> $pivotData
     */
    protected function attachPivotToRow(&$row, array $pivotData): void
    {
        if ($this->pivotClass !== null) {
            $class      = $this->pivotClass;
            $pivotValue = new $class($pivotData, $this->table);
        } elseif (is_array($row)) {
            $pivotValue = $pivotData;
        } else {
            $pivotValue = new Pivot($pivotData, $this->table);
        }

        static::setRowRelation($row, $this->pivotAccessor, $pivotValue);
    }

    /**
     * @param array<int, array|object> $results
     *
     * @return array<int, array|object>
     */
    protected function hydratePivotOnResults(array $results): array
    {
        foreach ($results as &$row) {
            $pivotData = $this->extractAndCleanPivotData($row);
            $this->attachPivotToRow($row, $pivotData);
        }
        unset($row);

        return $results;
    }

    // -------------------------------------------------------------------------
    // Pivot Write Methods (attach, detach, sync, toggle, updateExistingPivot)
    // -------------------------------------------------------------------------

    /**
     * Attach one or more related IDs (with optional pivot attributes) to the bound parent.
     *
     * @param int|string|array<int|string, mixed> $ids
     * @param array<string, mixed>                $attributes
     */
    public function attach($ids, array $attributes = []): bool
    {
        $parentId = $this->requireBoundParentId();
        $records  = $this->formatAttachRecords($parentId, $ids, $attributes);

        if ($records === []) {
            return true;
        }

        $db      = static::getModelConnection($this->parent);
        $builder = $db->table($this->table);

        if (count($records) === 1) {
            return (bool) $builder->insert($records[0]);
        }

        return $builder->insertBatch($records) !== false;
    }

    /**
     * Detach one, many, or all related IDs from the bound parent.
     *
     * @param int|string|array<int, int|string>|null $ids
     */
    public function detach($ids = null): int
    {
        $parentId = $this->requireBoundParentId();
        $db       = static::getModelConnection($this->parent);
        $builder  = $db->table($this->table);

        $builder->where($this->foreignPivotKey, $parentId);

        if ($ids !== null) {
            $idList = $this->normalizeIdList($ids);
            if ($idList === []) {
                return 0;
            }
            $builder->whereIn($this->relatedPivotKey, $idList);
        }

        $builder->delete();

        return (int) $db->affectedRows();
    }

    /**
     * Sync the intermediate table with a list of IDs (or [id => pivotAttributes]).
     *
     * @param array<int|string, mixed> $ids
     *
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated'  => [],
        ];

        $parentId = $this->requireBoundParentId();
        $db       = static::getModelConnection($this->parent);

        $currentRows = $db->table($this->table)
            ->select($this->relatedPivotKey)
            ->where($this->foreignPivotKey, $parentId)
            ->get()
            ->getResultArray();

        $current = array_column($currentRows, $this->relatedPivotKey);
        $records = $this->formatSyncList($ids);

        // Detach IDs not in the new list
        if ($detaching) {
            $detach = [];
            foreach ($current as $existingId) {
                if (! array_key_exists((string) $existingId, $records)) {
                    $detach[] = $existingId;
                }
            }

            if ($detach !== []) {
                $this->detach($detach);
                $changes['detached'] = array_values($detach);
            }
        }

        // Attach new IDs and update existing IDs if pivot attributes were provided
        $currentLookup = [];
        foreach ($current as $existingId) {
            $currentLookup[(string) $existingId] = true;
        }

        foreach ($records as $idKey => $attributes) {
            $rawId = $attributes['__id'];
            unset($attributes['__id']);

            if (! isset($currentLookup[(string) $idKey])) {
                $this->attach($rawId, $attributes);
                $changes['attached'][] = $rawId;
            } elseif ($attributes !== []) {
                if ($this->updateExistingPivot($rawId, $attributes)) {
                    $changes['updated'][] = $rawId;
                }
            }
        }

        return $changes;
    }

    /**
     * Sync without detaching existing associations.
     *
     * @param array<int|string, mixed> $ids
     *
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>, updated: array<int, int|string>}
     */
    public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Toggle associations for the given IDs.
     *
     * @param int|string|array<int|string, mixed> $ids
     *
     * @return array{attached: array<int, int|string>, detached: array<int, int|string>}
     */
    public function toggle($ids): array
    {
        $changes = [
            'attached' => [],
            'detached' => [],
        ];

        $parentId = $this->requireBoundParentId();
        $db       = static::getModelConnection($this->parent);

        $currentRows = $db->table($this->table)
            ->select($this->relatedPivotKey)
            ->where($this->foreignPivotKey, $parentId)
            ->get()
            ->getResultArray();

        $currentLookup = [];
        foreach ($currentRows as $row) {
            $currentLookup[(string) $row[$this->relatedPivotKey]] = true;
        }

        $records  = $this->formatSyncList(is_array($ids) ? $ids : [$ids]);
        $toDetach = [];

        foreach ($records as $idKey => $attributes) {
            $rawId = $attributes['__id'];
            unset($attributes['__id']);

            if (isset($currentLookup[(string) $idKey])) {
                $toDetach[]            = $rawId;
                $changes['detached'][] = $rawId;
            } else {
                $this->attach($rawId, $attributes);
                $changes['attached'][] = $rawId;
            }
        }

        if ($toDetach !== []) {
            $this->detach($toDetach);
        }

        return $changes;
    }

    /**
     * Update an existing pivot record for the given related ID.
     *
     * @param int|string           $id
     * @param array<string, mixed> $attributes
     */
    public function updateExistingPivot($id, array $attributes): bool
    {
        $parentId = $this->requireBoundParentId();
        $db       = static::getModelConnection($this->parent);

        if ($this->pivotWantsTimestamps && ! array_key_exists($this->pivotUpdatedAt, $attributes)) {
            $attributes[$this->pivotUpdatedAt] = $this->freshPivotTimestamp();
        }

        if ($attributes === []) {
            return false;
        }

        return (bool) $db->table($this->table)
            ->where($this->foreignPivotKey, $parentId)
            ->where($this->relatedPivotKey, $id)
            ->update($attributes);
    }

    /**
     * @return int|string
     */
    protected function requireBoundParentId()
    {
        if ($this->parentRow === null) {
            throw new LogicException('Cannot modify pivot table without a bound parent. Use ->for($parent) or access via an Entity.');
        }

        $parentId = static::getRowValue($this->parentRow, $this->parentKey);
        if ($parentId === null || $parentId === '') {
            throw new InvalidArgumentException('Parent model primary key cannot be null when modifying pivot records.');
        }

        return $parentId;
    }

    /**
     * @param int|string|array<int|string, mixed> $ids
     * @param array<string, mixed>                $attributes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatAttachRecords($parentId, $ids, array $attributes): array
    {
        if (! is_array($ids)) {
            $ids = [$ids];
        }

        $now     = $this->pivotWantsTimestamps ? $this->freshPivotTimestamp() : null;
        $records = [];

        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $relatedId = $key;
                $extra     = array_merge($attributes, $value);
            } else {
                $relatedId = is_object($value) ? static::getRowValue($value, $this->relatedKey) : $value;
                $extra     = $attributes;
            }

            $record = array_merge($extra, [
                $this->foreignPivotKey => $parentId,
                $this->relatedPivotKey => $relatedId,
            ]);

            if ($this->pivotWantsTimestamps) {
                $record[$this->pivotCreatedAt] ??= $now;
                $record[$this->pivotUpdatedAt] ??= $now;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param array<int|string, mixed> $ids
     *
     * @return array<string, array<string, mixed>>
     */
    protected function formatSyncList(array $ids): array
    {
        $formatted = [];
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $formatted[(string) $key] = array_merge($value, ['__id' => $key]);
            } else {
                $id                       = is_object($value) ? static::getRowValue($value, $this->relatedKey) : $value;
                $formatted[(string) $id]  = ['__id' => $id];
            }
        }

        return $formatted;
    }

    /**
     * @param int|string|array<int, mixed> $ids
     *
     * @return array<int, int|string>
     */
    protected function normalizeIdList($ids): array
    {
        if (! is_array($ids)) {
            $ids = [$ids];
        }

        $list = [];
        foreach ($ids as $item) {
            $list[] = is_object($item) ? static::getRowValue($item, $this->relatedKey) : $item;
        }

        return $list;
    }

    /**
     * @return int|string
     */
    protected function freshPivotTimestamp()
    {
        $dateFormat = static::getModelProperty($this->parent, 'dateFormat') ?: 'datetime';
        if ($dateFormat === 'int') {
            return time();
        }
        if ($dateFormat === 'date') {
            return date('Y-m-d');
        }

        return date('Y-m-d H:i:s');
    }

    // -------------------------------------------------------------------------
    // Existence & Aggregate Support
    // -------------------------------------------------------------------------

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

        $builder->join(
            $this->table,
            $this->table . '.' . $this->relatedPivotKey . ' = ' . $innerAlias . '.' . $this->relatedKey,
            'inner'
        );

        static::applyRawWhere(
            $builder,
            $db->protectIdentifiers($this->table . '.' . $this->foreignPivotKey)
            . ' = '
            . $db->protectIdentifiers($outerTable . '.' . $this->parentKey)
        );

        $this->applyPivotClauses($relatedModel, true);

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
        $ids = static::extractUniqueKeys($parentRows, $this->parentKey);
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

        $this->joinPivotTable($relatedModel);
        $this->applyPivotClauses($relatedModel, true);
        $this->applyPendingCalls($relatedModel, true);
        if ($callback !== null) {
            $callback($this->makeLiveQueryWrapper($relatedModel));
        }

        $builder = $relatedModel->builder();
        if (! $this->hasWithDeletedPendingCall()) {
            static::applySoftDeleteToBuilder($builder, $relatedModel);
        }

        $fkQualified = $this->table . '.' . $this->foreignPivotKey;
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
                $key = static::getRowValue($row, $this->parentKey);
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
            $key = static::getRowValue($row, $this->parentKey);
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
