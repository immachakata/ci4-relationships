<?php

namespace IsaacMachakata\Ci4Relationships\Relations;

use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use CodeIgniter\Entity\Entity;
use CodeIgniter\Model;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

abstract class Relation
{
    /**
     * Global morph map for polymorphic relationships.
     *
     * @var array<string, string>
     */
    protected static array $morphMap = [];

    /**
     * Whether polymorphic types must exist in the morph map.
     */
    protected static bool $requireMorphMap = false;

    /**
     * ReflectionProperty cache: [class => [property => ReflectionProperty]].
     *
     * @var array<string, array<string, ReflectionProperty>>
     */
    private static array $reflectionCache = [];

    /**
     * Default DBGroup cache per model class.
     *
     * @var array<string, string|null>
     */
    private static array $dbGroupCache = [];

    /**
     * Whether inflector helper has been loaded.
     */
    private static bool $inflectorLoaded = false;

    protected Model $parent;
    protected string $relatedClass;
    protected ?Model $related = null;
    protected string $relationName = '';

    /**
     * Single parent row/entity when executing queries for one parent.
     *
     * @var array|object|null
     */
    protected $parentRow = null;

    /**
     * Buffered query builder / model calls to apply when executing.
     *
     * @var array<int, array{0: string, 1: array}>
     */
    protected array $pendingCalls = [];

    /**
     * Selected columns from eager load syntax like "relation:id,name".
     *
     * @var array<int, string>|null
     */
    protected ?array $selectedColumns = null;

    /**
     * When true, __call forwards directly to $this->related (used in live existence callbacks).
     */
    protected bool $liveMode = false;

    /**
     * Methods considered part of WHERE groups when wrapping OR conditions.
     *
     * @var array<string, true>
     */
    private static array $whereMethods = [
        'where'            => true,
        'orWhere'          => true,
        'whereIn'          => true,
        'orWhereIn'        => true,
        'whereNotIn'       => true,
        'orWhereNotIn'     => true,
        'like'             => true,
        'orLike'           => true,
        'notLike'          => true,
        'orNotLike'        => true,
        'groupStart'       => true,
        'orGroupStart'     => true,
        'notGroupStart'    => true,
        'groupEnd'         => true,
    ];

    /**
     * Methods that contain OR logic and require grouping when replayed after relation constraints.
     *
     * @var array<string, true>
     */
    private static array $orWhereMethods = [
        'orWhere'       => true,
        'orWhereIn'     => true,
        'orWhereNotIn'  => true,
        'orLike'        => true,
        'orNotLike'     => true,
        'orGroupStart'  => true,
    ];

    /**
     * Methods skipped when building existence/aggregate subqueries.
     *
     * @var array<string, true>
     */
    private static array $nonSubqueryMethods = [
        'select'       => true,
        'selectMax'    => true,
        'selectMin'    => true,
        'selectAvg'    => true,
        'selectSum'    => true,
        'orderBy'      => true,
        'limit'        => true,
        'offset'       => true,
        'asArray'      => true,
        'asObject'     => true,
        'with'         => true,
        'without'      => true,
        'withoutRelations' => true,
        'withCount'    => true,
        'withSum'      => true,
        'withAvg'      => true,
        'withMin'      => true,
        'withMax'      => true,
        'withExists'   => true,
    ];

    public function __construct(Model $parent, string $relatedClass, string $relationName = '')
    {
        $this->parent       = $parent;
        $this->relatedClass = $relatedClass;
        $this->relationName = $relationName;
    }

    // -------------------------------------------------------------------------
    // Morph Map Configuration
    // -------------------------------------------------------------------------

    /**
     * Set or retrieve the global morph map for polymorphic relationships.
     *
     * @param array<string, string>|null $map
     *
     * @return array<string, string>
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        if (is_array($map)) {
            static::$morphMap = $merge ? array_merge(static::$morphMap, $map) : $map;
        }

        return static::$morphMap;
    }

    /**
     * Define a morph map and enforce its use for all polymorphic relations.
     *
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        static::$requireMorphMap = true;

        return static::morphMap($map, $merge);
    }

    /**
     * Get or set whether a morph map is required.
     */
    public static function requiresMorphMap(?bool $value = null): bool
    {
        if ($value !== null) {
            static::$requireMorphMap = $value;
        }

        return static::$requireMorphMap;
    }

    /**
     * Get the model class for a given morph alias.
     */
    public static function getMorphedModel(string $alias): ?string
    {
        return static::$morphMap[$alias] ?? null;
    }

    /**
     * Get the morph alias (or class name) for a given model class.
     */
    public static function getMorphAlias(string $class): string
    {
        $alias = array_search($class, static::$morphMap, true);
        if ($alias !== false) {
            return (string) $alias;
        }

        if (static::$requireMorphMap) {
            throw new InvalidArgumentException(sprintf(
                'No morph map defined for model [%s].',
                $class
            ));
        }

        return $class;
    }

    // -------------------------------------------------------------------------
    // Static Model & Row Helpers
    // -------------------------------------------------------------------------

    /**
     * Instantiate a fresh (non-shared) Model instance, sharing the parent DB connection
     * when the related model does not declare a different DBGroup.
     */
    public static function createModelInstance(string $modelClass, ?Model $parentModel = null): Model
    {
        $conn = null;
        if ($parentModel !== null) {
            $parentGroup  = static::getModelProperty($parentModel, 'DBGroup');
            $relatedGroup = static::getDefaultDBGroup($modelClass);

            if ($relatedGroup === $parentGroup) {
                $conn = static::getModelProperty($parentModel, 'db');
            }
        }

        if (class_exists($modelClass)) {
            $instance = new $modelClass($conn);
            if ($instance instanceof Model) {
                return $instance;
            }
        }

        if (function_exists('model')) {
            $instance = model($modelClass, false, $conn);
            if ($instance instanceof Model) {
                return $instance;
            }
        }

        throw new InvalidArgumentException(sprintf('Related model [%s] could not be instantiated.', $modelClass));
    }

    /**
     * Inspect a model's default DBGroup without instantiating it.
     */
    private static function getDefaultDBGroup(string $modelClass): ?string
    {
        if (array_key_exists($modelClass, static::$dbGroupCache)) {
            return static::$dbGroupCache[$modelClass];
        }

        if (! class_exists($modelClass)) {
            return static::$dbGroupCache[$modelClass] = null;
        }

        $defaults = (new ReflectionClass($modelClass))->getDefaultProperties();

        return static::$dbGroupCache[$modelClass] = $defaults['DBGroup'] ?? null;
    }

    /**
     * Read a protected/private property on an object using cached ReflectionProperty.
     *
     * @return mixed
     */
    public static function getObjectProperty(object $object, string $property)
    {
        $class = get_class($object);

        if (! isset(self::$reflectionCache[$class][$property])) {
            $ref = new ReflectionClass($object);
            while ($ref && ! $ref->hasProperty($property)) {
                $ref = $ref->getParentClass();
            }

            if (! $ref) {
                return null;
            }

            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            self::$reflectionCache[$class][$property] = $prop;
        }

        return self::$reflectionCache[$class][$property]->getValue($object);
    }

    /**
     * Write a protected/private property on an object using cached ReflectionProperty.
     *
     * @param mixed $value
     */
    public static function setObjectProperty(object $object, string $property, $value): void
    {
        $class = get_class($object);

        if (! isset(self::$reflectionCache[$class][$property])) {
            $ref = new ReflectionClass($object);
            while ($ref && ! $ref->hasProperty($property)) {
                $ref = $ref->getParentClass();
            }

            if (! $ref) {
                return;
            }

            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);
            self::$reflectionCache[$class][$property] = $prop;
        }

        self::$reflectionCache[$class][$property]->setValue($object, $value);
    }

    /**
     * @return mixed
     */
    public static function getModelProperty(Model $model, string $property)
    {
        return static::getObjectProperty($model, $property);
    }

    public static function getModelTable(Model $model): string
    {
        return (string) static::getModelProperty($model, 'table');
    }

    public static function getModelPrimaryKey(Model $model): string
    {
        return (string) (static::getModelProperty($model, 'primaryKey') ?: 'id');
    }

    public static function getModelReturnType(Model $model): string
    {
        return (string) (static::getModelProperty($model, 'tempReturnType')
            ?? static::getModelProperty($model, 'returnType')
            ?? 'array');
    }

    public static function modelUsesSoftDeletes(Model $model): bool
    {
        $temp = static::getModelProperty($model, 'tempUseSoftDeletes');
        if ($temp !== null) {
            return (bool) $temp;
        }

        return (bool) static::getModelProperty($model, 'useSoftDeletes');
    }

    public static function getModelDeletedField(Model $model): string
    {
        return (string) (static::getModelProperty($model, 'deletedField') ?: 'deleted_at');
    }

    public static function getModelConnection(Model $model): BaseConnection
    {
        return static::getModelProperty($model, 'db');
    }

    /**
     * Singularize a table/word using CI4 inflector helper if available.
     */
    public static function singularize(string $word): string
    {
        if (! self::$inflectorLoaded && function_exists('helper')) {
            helper('inflector');
            self::$inflectorLoaded = true;
        }

        if (function_exists('singular')) {
            return singular($word);
        }

        if (substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        if (substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Pluralize a table/word using CI4 inflector helper if available.
     */
    public static function pluralize(string $word): string
    {
        if (! self::$inflectorLoaded && function_exists('helper')) {
            helper('inflector');
            self::$inflectorLoaded = true;
        }

        if (function_exists('plural')) {
            return plural($word);
        }

        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        if (substr($word, -1) !== 's') {
            return $word . 's';
        }

        return $word;
    }

    public static function snakeCase(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', '', ucwords($value));
        $value = strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $value));

        return $value;
    }

    /**
     * Read a field/attribute value from an array, stdClass, or Entity.
     *
     * @param array|object $row
     *
     * @return mixed
     */
    public static function getRowValue($row, string $key)
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        if ($row instanceof Entity) {
            $raw = $row->toRawArray();
            if (array_key_exists($key, $raw)) {
                return $raw[$key];
            }

            if (method_exists($row, 'relationLoaded') && $row->relationLoaded($key)) {
                return $row->getRelation($key);
            }

            return null;
        }

        if (is_object($row)) {
            return $row->{$key} ?? null;
        }

        return null;
    }

    /**
     * Set a field/attribute on an array, stdClass, or Entity.
     *
     * @param array|object $row
     * @param mixed        $value
     */
    public static function setRowValue(&$row, string $key, $value): void
    {
        if (is_array($row)) {
            $row[$key] = $value;

            return;
        }

        if (is_object($row)) {
            $row->{$key} = $value;
        }
    }

    /**
     * Set an eager-loaded relation onto a parent row without polluting Entity::hasChanged().
     *
     * @param array|object $row
     * @param mixed        $value
     */
    public static function setRowRelation(&$row, string $name, $value): void
    {
        if (is_array($row)) {
            $row[$name] = $value;

            return;
        }

        if ($row instanceof Entity) {
            if (method_exists($row, 'setRelation')) {
                $row->setRelation($name, $value);

                return;
            }

            $attributes = static::getObjectProperty($row, 'attributes');
            $original   = static::getObjectProperty($row, 'original');

            if (is_array($attributes) && is_array($original)) {
                $attributes[$name] = $value;
                $original[$name]   = $value;
                static::setObjectProperty($row, 'attributes', $attributes);
                static::setObjectProperty($row, 'original', $original);

                return;
            }
        }

        if (is_object($row)) {
            $row->{$name} = $value;
        }
    }

    /**
     * Sync a specific attribute on a plain Entity into $original so hasChanged() stays false.
     *
     * @param array|object $row
     */
    public static function syncRowOriginalKey(&$row, string $key): void
    {
        if (! ($row instanceof Entity)) {
            return;
        }

        $attributes = static::getObjectProperty($row, 'attributes');
        $original   = static::getObjectProperty($row, 'original');

        if (is_array($attributes) && is_array($original) && array_key_exists($key, $attributes)) {
            $original[$key] = $attributes[$key];
            static::setObjectProperty($row, 'original', $original);
        }
    }

    /**
     * Remove a temporary key (like __pivot_*) from an array, stdClass, or Entity.
     *
     * @param array|object $row
     */
    public static function unsetRowKey(&$row, string $key): void
    {
        if (is_array($row)) {
            unset($row[$key]);

            return;
        }

        if ($row instanceof Entity) {
            $attributes = static::getObjectProperty($row, 'attributes');
            $original   = static::getObjectProperty($row, 'original');

            if (is_array($attributes)) {
                unset($attributes[$key]);
                static::setObjectProperty($row, 'attributes', $attributes);
            }
            if (is_array($original)) {
                unset($original[$key]);
                static::setObjectProperty($row, 'original', $original);
            }

            return;
        }

        if (is_object($row)) {
            unset($row->{$key});
        }
    }

    /**
     * Extract unique non-null, non-empty-string keys from parent rows.
     *
     * @param array<int, array|object> $rows
     *
     * @return array<int, int|string>
     */
    public static function extractUniqueKeys(array $rows, string $key): array
    {
        $seen = [];
        $ids  = [];

        foreach ($rows as $row) {
            $val = static::getRowValue($row, $key);
            if ($val === null || $val === '') {
                continue;
            }

            $lookup = (string) $val;
            if (! isset($seen[$lookup])) {
                $seen[$lookup] = true;
                $ids[]         = $val;
            }
        }

        return $ids;
    }

    /**
     * Add a raw SQL condition to a CI4 BaseBuilder in a version-safe way.
     */
    public static function applyRawWhere(BaseBuilder $builder, string $sql, string $boolean = 'AND'): void
    {
        $isOr = strtoupper(trim($boolean)) === 'OR';

        if (class_exists(RawSql::class)) {
            $raw = new RawSql($sql);
            if ($isOr) {
                $builder->orWhere($raw);
            } else {
                $builder->where($raw);
            }

            return;
        }

        if ($isOr) {
            $builder->orWhere($sql, null, false);
        } else {
            $builder->where($sql, null, false);
        }
    }

    /**
     * Apply soft-delete filtering directly on a builder when building subqueries.
     */
    protected static function applySoftDeleteToBuilder(BaseBuilder $builder, Model $model, ?string $tableAlias = null): void
    {
        if (! static::modelUsesSoftDeletes($model)) {
            return;
        }

        $table = $tableAlias ?? static::getModelTable($model);
        $field = static::getModelDeletedField($model);

        $builder->where($table . '.' . $field, null);
    }

    // -------------------------------------------------------------------------
    // Instance Configuration & Fluent API
    // -------------------------------------------------------------------------

    public function getParent(): Model
    {
        return $this->parent;
    }

    public function getRelatedClass(): string
    {
        return $this->relatedClass;
    }

    public function getRelationName(): string
    {
        return $this->relationName;
    }

    public function setRelationName(string $name): self
    {
        $this->relationName = $name;

        return $this;
    }

    /**
     * Bind a parent row/entity for executing direct queries or writes.
     *
     * @param array|object $parentRow
     */
    public function for($parentRow): self
    {
        $this->parentRow = $parentRow;

        return $this;
    }

    /**
     * Set specific columns to select during eager loading.
     *
     * @param array<int, string>|null $columns
     */
    public function setSelectedColumns(?array $columns): self
    {
        $this->selectedColumns = $columns;

        return $this;
    }

    /**
     * Get a fresh instance of the related model.
     */
    public function newRelatedInstance(): Model
    {
        return static::createModelInstance($this->relatedClass, $this->parent);
    }

    /**
     * Get (and lazily create) the underlying related model instance.
     */
    public function getRelated(): Model
    {
        if ($this->related === null) {
            $this->related = $this->newRelatedInstance();
        }

        return $this->related;
    }

    /**
     * Record a fluent query builder / model method call, or forward immediately in liveMode.
     *
     * @param array<int, mixed> $arguments
     *
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        if ($this->liveMode && $this->related !== null) {
            $result = $this->related->{$method}(...$arguments);

            return $result === $this->related ? $this : $result;
        }

        $this->pendingCalls[] = [$method, $arguments];

        return $this;
    }

    /**
     * Apply buffered query builder calls onto a target Model instance.
     */
    protected function applyPendingCalls(Model $model, bool $forSubquery = false, array $nestedEagerLoads = []): Model
    {
        if (! $forSubquery && $this->selectedColumns !== null && $this->selectedColumns !== []) {
            $model->select($this->selectedColumns);
        }

        $softDeleteCalls = [];
        $otherCalls      = [];
        $whereCalls      = [];
        $hasOr           = false;

        foreach ($this->pendingCalls as [$method, $args]) {
            if ($forSubquery && isset(self::$nonSubqueryMethods[$method])) {
                continue;
            }

            if ($method === 'withDeleted' || $method === 'onlyDeleted') {
                $softDeleteCalls[] = [$method, $args];
                continue;
            }

            if (isset(self::$whereMethods[$method])) {
                if (isset(self::$orWhereMethods[$method])) {
                    $hasOr = true;
                }
                $whereCalls[] = [$method, $args];
                continue;
            }

            $otherCalls[] = [$method, $args];
        }

        foreach ($softDeleteCalls as [$method, $args]) {
            $model->{$method}(...$args);
        }

        foreach ($otherCalls as [$method, $args]) {
            $model->{$method}(...$args);
        }

        if ($whereCalls !== []) {
            if ($hasOr) {
                $model->groupStart();
            }

            foreach ($whereCalls as [$method, $args]) {
                $model->{$method}(...$args);
            }

            if ($hasOr) {
                $model->groupEnd();
            }
        }

        if (! $forSubquery && $nestedEagerLoads !== []) {
            $this->applyNestedEagerLoads($model, $nestedEagerLoads);
        }

        return $model;
    }

    /**
     * Forward nested eager-load tree to a related model.
     */
    protected function applyNestedEagerLoads(Model $model, array $nestedEagerLoads): void
    {
        if ($nestedEagerLoads === []) {
            return;
        }

        if (! method_exists($model, 'withTree')) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] must use RelationshipsTrait to eager-load nested relations.',
                get_class($model)
            ));
        }

        $model->withTree($nestedEagerLoads);
    }

    /**
     * Build a live query clone of this relation backed by the given Model for use in closures.
     */
    protected function makeLiveQueryWrapper(Model $queryModel): self
    {
        $wrapper           = clone $this;
        $wrapper->related  = $queryModel;
        $wrapper->liveMode = true;

        return $wrapper;
    }

    /**
     * Check whether pendingCalls includes withDeleted().
     */
    protected function hasWithDeletedPendingCall(): bool
    {
        $withDeleted = false;
        foreach ($this->pendingCalls as [$method, $args]) {
            if ($method === 'withDeleted') {
                $withDeleted = $args[0] ?? true;
            }
        }

        return (bool) $withDeleted;
    }

    // -------------------------------------------------------------------------
    // Direct Query Execution (for $user->posts()->where(...)->findAll() etc.)
    // -------------------------------------------------------------------------

    /**
     * Build a ready-to-execute Model query for the bound parent row.
     */
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
        $this->applyPendingCalls($query);

        return $query;
    }

    /**
     * Execute and return all matching related rows.
     *
     * @return array<int, array|object>
     */
    public function findAll(?int $limit = null, int $offset = 0): array
    {
        return $this->toQuery()->findAll($limit, $offset);
    }

    /**
     * Alias for findAll() familiar to query builder users.
     *
     * @return array<int, array|object>
     */
    public function get(?int $limit = null, int $offset = 0): array
    {
        return $this->findAll($limit, $offset);
    }

    /**
     * Execute and return the first matching related row.
     *
     * @return array|object|null
     */
    public function first()
    {
        return $this->toQuery()->first();
    }

    /**
     * Find a related row (or rows) by primary key scoped to the parent.
     *
     * @param array|int|string|null $id
     *
     * @return array|object|null
     */
    public function find($id = null)
    {
        return $this->toQuery()->find($id);
    }

    /**
     * @return array<int, mixed>|null
     */
    public function findColumn(string $columnName): ?array
    {
        return $this->toQuery()->findColumn($columnName);
    }

    /**
     * @return array<int, array|object>|null
     */
    public function paginate(?int $perPage = null, string $group = 'default', ?int $page = null, int $segment = 0): ?array
    {
        return $this->toQuery()->paginate($perPage, $group, $page, $segment);
    }

    public function count(bool $reset = true): int
    {
        return (int) $this->toQuery()->countAllResults($reset);
    }

    public function countAllResults(bool $reset = true, bool $test = false): int
    {
        return (int) $this->toQuery()->countAllResults($reset, $test);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * @return int|float|null
     */
    public function sum(string $column)
    {
        return $this->runDirectAggregate('SUM', $column);
    }

    /**
     * @return float|null
     */
    public function avg(string $column)
    {
        $val = $this->runDirectAggregate('AVG', $column);

        return $val === null ? null : (float) $val;
    }

    /**
     * @return mixed
     */
    public function min(string $column)
    {
        return $this->runDirectAggregate('MIN', $column);
    }

    /**
     * @return mixed
     */
    public function max(string $column)
    {
        return $this->runDirectAggregate('MAX', $column);
    }

    /**
     * @return mixed
     */
    protected function runDirectAggregate(string $function, string $column)
    {
        $query = $this->toQuery();
        $table = static::getModelTable($query);

        $qualifiedColumn = strpos($column, '.') === false ? $table . '.' . $column : $column;
        $builder         = $query->builder();

        if (static::modelUsesSoftDeletes($query)) {
            $builder->where($table . '.' . static::getModelDeletedField($query), null);
        }

        $row = $builder->select(sprintf('%s(%s) AS __agg_value', $function, $qualifiedColumn), false)
            ->get()
            ->getRowArray();

        $val = $row['__agg_value'] ?? null;
        if ($val === null) {
            return null;
        }

        if ($function === 'SUM') {
            return is_numeric($val) ? (strpos((string) $val, '.') !== false ? (float) $val : (int) $val) : $val;
        }

        return $val;
    }

    public function chunk(int $size, Closure $callback): void
    {
        $this->toQuery()->chunk($size, $callback);
    }

    /**
     * Delete related records scoped to the parent.
     *
     * @param array|int|string|null $id
     */
    public function delete($id = null, bool $purge = false): bool
    {
        $query = $this->toQuery();

        if ($id !== null) {
            return (bool) $query->delete($id, $purge);
        }

        $builder = $query->builder();
        if (! $purge && static::modelUsesSoftDeletes($query)) {
            $deletedField = static::getModelDeletedField($query);
            $dateFormat   = static::getModelProperty($query, 'dateFormat') ?: 'datetime';
            $now          = $dateFormat === 'int' ? time() : ($dateFormat === 'date' ? date('Y-m-d') : date('Y-m-d H:i:s'));

            return (bool) $builder->update([$deletedField => $now]);
        }

        return (bool) $builder->delete();
    }

    /**
     * Update related records scoped to the parent.
     *
     * @param array<string, mixed>|object $data
     */
    public function update($data): bool
    {
        if (is_object($data)) {
            $data = method_exists($data, 'toRawArray') ? $data->toRawArray() : (array) $data;
        }

        return (bool) $this->toQuery()->builder()->update($data);
    }

    // -------------------------------------------------------------------------
    // Abstract & Shared Eager Loading / Existence / Aggregate Contracts
    // -------------------------------------------------------------------------

    /**
     * Apply constraints for a single parent row onto $query.
     *
     * @param array|object $parentRow
     */
    abstract protected function addConstraints(Model $query, $parentRow): void;

    /**
     * Eager load this relation onto all $parentRows.
     *
     * @param array<int, array|object> $parentRows
     * @param array<string, array>     $nestedEagerLoads
     */
    abstract public function eagerLoad(array &$parentRows, array $nestedEagerLoads = []): void;

    /**
     * Build the SQL subquery for has/whereHas/withCount/withSum/etc.
     */
    abstract public function getRelationExistenceQuery(
        Model $outerModel,
        string $outerTable,
        ?callable $callback = null,
        string $selectExpr = '1',
        bool $allowConstraints = true
    ): string;

    /**
     * Lazy-load an aggregate (COUNT, SUM, AVG, MIN, MAX, EXISTS) onto already-fetched rows.
     *
     * @param array<int, array|object> $parentRows
     */
    abstract public function loadAggregate(
        array &$parentRows,
        string $alias,
        string $function,
        string $column,
        ?callable $callback = null
    ): void;
}
