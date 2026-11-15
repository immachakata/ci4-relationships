<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

class MorphMany extends MorphOneOrMany
{
    /**
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void
    {
        $ids = static::extractUniqueKeys($parentRows, $this->localKey);

        if ($ids === []) {
            foreach ($parentRows as &$row) {
                static::setRowRelation($row, $this->relationName, []);
            }
            unset($row);

            return;
        }

        $model = $this->newRelatedInstance();
        $table = static::getModelTable($model);

        $model->where($table . '.' . $this->morphType, $this->morphClass);
        $model->whereIn($table . '.' . $this->foreignKey, $ids);
        $this->applyPendingCalls($model, false, $nestedEagerLoads);

        $results = $model->findAll();

        $grouped = [];
        foreach ($results as $relatedRow) {
            $fk = static::getRowValue($relatedRow, $this->foreignKey);
            if ($fk === null || $fk === '') {
                continue;
            }
            $grouped[(string) $fk][] = $relatedRow;
        }

        foreach ($parentRows as &$row) {
            $localVal = static::getRowValue($row, $this->localKey);
            $lookup   = ($localVal !== null && $localVal !== '') ? (string) $localVal : null;
            $matched  = ($lookup !== null && array_key_exists($lookup, $grouped))
                ? $grouped[$lookup]
                : [];

            static::setRowRelation($row, $this->relationName, $matched);
        }
        unset($row);
    }
}
