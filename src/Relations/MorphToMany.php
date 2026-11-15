<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Model;

class MorphToMany extends BelongsToMany
{
    protected string $morphType;
    protected string $morphClass;
    protected bool $inverse;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        string $relationName = '',
        bool $inverse = false
    ) {
        parent::__construct(
            $parent,
            $relatedClass,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );

        $this->morphType  = $name . '_type';
        $this->inverse    = $inverse;
        $this->morphClass = static::getMorphAlias(
            $inverse ? $relatedClass : get_class($parent)
        );
    }

    public function getMorphType(): string
    {
        return $this->morphType;
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    protected function applyPivotClauses(Model $query, bool $forSubquery = false): void
    {
        $query->where($this->table . '.' . $this->morphType, $this->morphClass);

        parent::applyPivotClauses($query, $forSubquery);
    }

    /**
     * @param int|string|array<int, mixed> $ids
     *
     * @return int
     */
    public function detach($ids = null): int
    {
        $parentId = $this->requireBoundParentId();
        $db       = static::getModelConnection($this->parent);
        $builder  = $db->table($this->table);

        $builder->where($this->foreignPivotKey, $parentId);
        $builder->where($this->morphType, $this->morphClass);

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
            ->where($this->morphType, $this->morphClass)
            ->get()
            ->getResultArray();

        $current = array_column($currentRows, $this->relatedPivotKey);
        $records = $this->formatSyncList($ids);

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
            ->where($this->morphType, $this->morphClass)
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
            ->where($this->morphType, $this->morphClass)
            ->update($attributes);
    }

    /**
     * @param int|string|array<int|string, mixed> $ids
     * @param array<string, mixed>                $attributes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatAttachRecords($parentId, $ids, array $attributes): array
    {
        $attributes[$this->morphType] = $this->morphClass;

        return parent::formatAttachRecords($parentId, $ids, $attributes);
    }
}
