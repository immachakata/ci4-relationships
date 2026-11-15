<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use CodeIgniter\Model;

abstract class MorphOneOrMany extends HasOneOrMany
{
    protected string $morphType;
    protected string $morphClass;

    public function __construct(
        Model $parent,
        string $relatedClass,
        string $type,
        string $id,
        string $localKey,
        string $relationName = ''
    ) {
        parent::__construct($parent, $relatedClass, $id, $localKey, $relationName);

        $this->morphType  = $type;
        $this->morphClass = static::getMorphAlias(get_class($parent));
    }

    public function getMorphType(): string
    {
        return $this->morphType;
    }

    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    /**
     * @param array|object $parentRow
     */
    protected function addConstraints(Model $query, $parentRow): void
    {
        parent::addConstraints($query, $parentRow);

        $table = static::getModelTable($query);
        $query->where($table . '.' . $this->morphType, $this->morphClass);
    }

    /**
     * @param array|object $record
     */
    public function save($record): bool
    {
        static::setRowValue($record, $this->morphType, $this->morphClass);

        return parent::save($record);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array|object|false
     */
    public function create(array $attributes = [])
    {
        $attributes[$this->morphType] = $this->morphClass;

        return parent::create($attributes);
    }

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
        $builder->where($innerAlias . '.' . $this->morphType, $this->morphClass);

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
        $relatedTable = static::getModelTable($this->getRelated());
        $morphType    = $this->morphType;
        $morphClass   = $this->morphClass;

        $wrappedCallback = static function ($query) use ($relatedTable, $morphType, $morphClass, $callback): void {
            $query->where($relatedTable . '.' . $morphType, $morphClass);
            if ($callback !== null) {
                $callback($query);
            }
        };

        parent::loadAggregate($parentRows, $alias, $function, $column, $wrappedCallback);
    }
}
