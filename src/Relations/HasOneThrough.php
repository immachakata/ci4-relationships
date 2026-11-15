<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

class HasOneThrough extends HasOneOrManyThrough
{
    /**
     * Eager load hasOneThrough relation onto all parent rows.
     *
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void
    {
        $ids = static::extractUniqueKeys($parentRows, $this->localKey);

        if ($ids === []) {
            foreach ($parentRows as &$row) {
                static::setRowRelation($row, $this->relationName, null);
            }
            unset($row);

            return;
        }

        $model        = $this->newRelatedInstance();
        $throughModel = $this->newThroughInstance();
        $throughTable = static::getModelTable($throughModel);

        $this->joinThroughTable($model, $throughModel);
        $model->whereIn($throughTable . '.' . $this->firstKey, $ids);
        $this->applySelectWithThroughKey($model, $throughModel);
        $this->applyPendingCalls($model, false, $nestedEagerLoads);

        $results = $model->findAll();
        $grouped = [];

        foreach ($results as $relatedRow) {
            $parentFk = static::getRowValue($relatedRow, '__through_parent_key');
            static::unsetRowKey($relatedRow, '__through_parent_key');

            if ($parentFk === null || $parentFk === '') {
                continue;
            }

            $lookup = (string) $parentFk;
            if (! array_key_exists($lookup, $grouped)) {
                $grouped[$lookup] = $relatedRow;
            }
        }

        foreach ($parentRows as &$row) {
            $localVal = static::getRowValue($row, $this->localKey);
            $lookup   = ($localVal !== null && $localVal !== '') ? (string) $localVal : null;
            $matched  = ($lookup !== null && array_key_exists($lookup, $grouped))
                ? $grouped[$lookup]
                : null;

            static::setRowRelation($row, $this->relationName, $matched);
        }
        unset($row);
    }
}
