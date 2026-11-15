<?php

namespace IsaacMachakata\Ci4Relationships;

use CodeIgniter\Model;
use InvalidArgumentException;
use IsaacMachakata\Ci4Relationships\Relations\BelongsTo;
use IsaacMachakata\Ci4Relationships\Relations\BelongsToMany;
use IsaacMachakata\Ci4Relationships\Relations\HasMany;
use IsaacMachakata\Ci4Relationships\Relations\HasManyThrough;
use IsaacMachakata\Ci4Relationships\Relations\HasOne;
use IsaacMachakata\Ci4Relationships\Relations\HasOneThrough;
use IsaacMachakata\Ci4Relationships\Relations\MorphMany;
use IsaacMachakata\Ci4Relationships\Relations\MorphOne;
use IsaacMachakata\Ci4Relationships\Relations\MorphTo;
use IsaacMachakata\Ci4Relationships\Relations\MorphToMany;
use IsaacMachakata\Ci4Relationships\Relations\Relation;

trait RelationshipsTrait
{
    /**
     * Relationships registered explicitly (e.g. inside initialize()).
     *
     * @var array<string, Relation>
     */
    protected array $relations = [];

    /**
     * Parsed eager-load tree queued for the next find query.
     *
     * @var array<string, array{columns: array<int, string>|null, constraints: array<int, callable>, nested: array}>
     */
    protected array $activeRelations = [];

    /**
     * Relation names to exclude from default $with for the next query.
     *
     * @var array<string, true>
     */
    protected array $withoutRelations = [];

    /**
     * Whether to suppress all default $with / $withCount for the next query.
     */
    protected bool $disableDefaultRelations = false;

    /**
     * Queued aggregate definitions for the next find query.
     *
     * @var array<int, array{relation: string, alias: string, function: string, column: string, callback: callable|null}>
     */
    protected array $activeAggregates = [];

    /**
     * Aggregate column casts to apply in afterFind: [alias => function].
     *
     * @var array<string, string>
     */
    protected array $pendingAggregateCasts = [];

    /**
     * Current relation method name being resolved via resolveRelation().
     */
    protected ?string $resolvingRelationName = null;

    /**
     * Guard against circular default $with loops: [ModelClass => true].
     *
     * @var array<string, bool>
     */
    protected static array $resolvingDefaultWith = [];

    /**
     * Automatically register callbacks when the model is constructed.
     */
    public function __construct()
    {
        parent::__construct(...func_get_args());
        $this->bootRelationships();
    }

    /**
     * Ensure beforeFind and afterFind callbacks are registered.
     */
    protected function bootRelationships(): void
    {
        if (property_exists($this, 'beforeFind') && is_array($this->beforeFind)) {
            if (! in_array('applyRelationshipAggregates', $this->beforeFind, true)) {
                array_unshift($this->beforeFind, 'applyRelationshipAggregates');
            }
        }

        if (property_exists($this, 'afterFind') && is_array($this->afterFind)) {
            if (! in_array('applyRelations', $this->afterFind, true)) {
                array_unshift($this->afterFind, 'applyRelations');
            }
        }
    }

    // =========================================================================
    // Relationship Definitions (Laravel-style methods & legacy initialize())
    // =========================================================================

    /**
     * Define a one-to-one relationship.
     *
     * Supports both:
     *   $this->hasOne(ProfileModel::class, 'user_id', 'id')
     *   $this->hasOne('profile', ProfileModel::class, 'user_id', 'id')
     *
     * @param string|object $related
     */
    public function hasOne($related, ?string $foreignKey = null, ?string $localKey = null, ?string $legacyLocalKey = null): HasOne
    {
        $isLegacy = false;
        $name     = null;

        if ($foreignKey !== null && $this->isModelReference($foreignKey) && ! $this->isModelReference($related)) {
            $isLegacy   = true;
            $name       = (string) $related;
            $related    = $foreignKey;
            $foreignKey = $localKey;
            $localKey   = $legacyLocalKey;
        } else {
            $name = $this->guessRelationName();
        }

        $modelClass = $this->normalizeModelClass($related);
        $localKey   = $localKey ?: ($this->primaryKey ?: 'id');
        $foreignKey = $foreignKey ?: (Relation::singularize((string) $this->table) . '_' . $localKey);

        $relation = new HasOne($this, $modelClass, $foreignKey, $localKey, $name);

        if ($isLegacy) {
            $this->relations[$name] = $relation;
        }

        return $relation;
    }

    /**
     * Define a one-to-many relationship.
     *
     * Supports both:
     *   $this->hasMany(PostModel::class, 'user_id', 'id')
     *   $this->hasMany('posts', PostModel::class, 'user_id', 'id')
     *
     * @param string|object $related
     */
    public function hasMany($related, ?string $foreignKey = null, ?string $localKey = null, ?string $legacyLocalKey = null): HasMany
    {
        $isLegacy = false;
        $name     = null;

        if ($foreignKey !== null && $this->isModelReference($foreignKey) && ! $this->isModelReference($related)) {
            $isLegacy   = true;
            $name       = (string) $related;
            $related    = $foreignKey;
            $foreignKey = $localKey;
            $localKey   = $legacyLocalKey;
        } else {
            $name = $this->guessRelationName();
        }

        $modelClass = $this->normalizeModelClass($related);
        $localKey   = $localKey ?: ($this->primaryKey ?: 'id');
        $foreignKey = $foreignKey ?: (Relation::singularize((string) $this->table) . '_' . $localKey);

        $relation = new HasMany($this, $modelClass, $foreignKey, $localKey, $name);

        if ($isLegacy) {
            $this->relations[$name] = $relation;
        }

        return $relation;
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     *
     * Supports both:
     *   $this->belongsTo(UserModel::class, 'user_id', 'id')
     *   $this->belongsTo('user', UserModel::class, 'user_id', 'id')
     *
     * @param string|object $related
     */
    public function belongsTo(
        $related,
        ?string $foreignKey = null,
        ?string $ownerKey = null,
        ?string $relationName = null,
        ?string $legacyOwnerKey = null
    ): BelongsTo {
        $isLegacy = false;

        if ($foreignKey !== null && $this->isModelReference($foreignKey) && ! $this->isModelReference($related)) {
            $isLegacy     = true;
            $relationName = (string) $related;
            $related      = $foreignKey;
            $foreignKey   = $ownerKey;
            $ownerKey     = $legacyOwnerKey;
        } else {
            $relationName = $relationName ?: $this->guessRelationName();
        }

        $modelClass   = $this->normalizeModelClass($related);
        $relatedModel = Relation::createModelInstance($modelClass, $this);

        $ownerKey   = $ownerKey ?: Relation::getModelPrimaryKey($relatedModel);
        $foreignKey = $foreignKey ?: (Relation::snakeCase($relationName) . '_' . $ownerKey);

        $relation = new BelongsTo($this, $modelClass, $foreignKey, $ownerKey, $relationName);

        if ($isLegacy) {
            $this->relations[$relationName] = $relation;
        }

        return $relation;
    }

    /**
     * Define a many-to-many relationship.
     *
     * Supports both:
     *   $this->belongsToMany(RoleModel::class, 'role_user', 'user_id', 'role_id', 'id', 'id')
     *   $this->belongsToMany('roles', RoleModel::class, 'role_user', 'user_id', 'role_id', 'id', 'id')
     *
     * @param string|object $related
     */
    public function belongsToMany(
        $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relationName = null,
        ?string $legacyRelatedKey = null
    ): BelongsToMany {
        $isLegacy = false;

        if ($table !== null && $this->isModelReference($table) && ! $this->isModelReference($related)) {
            $isLegacy        = true;
            $relationName    = (string) $related;
            $related         = $table;
            $table           = $foreignPivotKey;
            $foreignPivotKey = $relatedPivotKey;
            $relatedPivotKey = $parentKey;
            $parentKey       = $relatedKey;
            $relatedKey      = $legacyRelatedKey;
        } else {
            $relationName = $relationName ?: $this->guessRelationName();
        }

        $modelClass   = $this->normalizeModelClass($related);
        $relatedModel = Relation::createModelInstance($modelClass, $this);
        $relatedTable = Relation::getModelTable($relatedModel);

        $parentKey       = $parentKey ?: ($this->primaryKey ?: 'id');
        $relatedKey      = $relatedKey ?: Relation::getModelPrimaryKey($relatedModel);
        $foreignPivotKey = $foreignPivotKey ?: (Relation::singularize((string) $this->table) . '_' . $parentKey);
        $relatedPivotKey = $relatedPivotKey ?: (Relation::singularize($relatedTable) . '_' . $relatedKey);
        $table           = $table ?: $this->joiningTable((string) $this->table, $relatedTable);

        $relation = new BelongsToMany(
            $this,
            $modelClass,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );

        if ($isLegacy) {
            $this->relations[$relationName] = $relation;
        }

        return $relation;
    }

    /**
     * Define a has-one-through relationship.
     *
     * @param string|object $related
     * @param string|object $through
     */
    public function hasOneThrough(
        $related,
        $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
        ?string $legacySecondLocalKey = null
    ): HasOneThrough {
        $isLegacy = false;
        $name     = null;

        if ($through !== null && $this->isModelReference($through) && ! $this->isModelReference($related)) {
            $isLegacy       = true;
            $name           = (string) $related;
            $related        = $through;
            $through        = $firstKey;
            $firstKey       = $secondKey;
            $secondKey      = $localKey;
            $localKey       = $secondLocalKey;
            $secondLocalKey = $legacySecondLocalKey;
        } else {
            $name = $this->guessRelationName();
        }

        $relatedClass = $this->normalizeModelClass($related);
        $throughClass = $this->normalizeModelClass($through);

        $throughModel = Relation::createModelInstance($throughClass, $this);
        $throughTable = Relation::getModelTable($throughModel);

        $localKey       = $localKey ?: ($this->primaryKey ?: 'id');
        $secondLocalKey = $secondLocalKey ?: Relation::getModelPrimaryKey($throughModel);
        $firstKey       = $firstKey ?: (Relation::singularize((string) $this->table) . '_' . $localKey);
        $secondKey      = $secondKey ?: (Relation::singularize($throughTable) . '_' . $secondLocalKey);

        $relation = new HasOneThrough(
            $this,
            $relatedClass,
            $throughClass,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey,
            $name
        );

        if ($isLegacy) {
            $this->relations[$name] = $relation;
        }

        return $relation;
    }

    /**
     * Define a has-many-through relationship.
     *
     * @param string|object $related
     * @param string|object $through
     */
    public function hasManyThrough(
        $related,
        $through,
        ?string $firstKey = null,
        ?string $secondKey = null,
        ?string $localKey = null,
        ?string $secondLocalKey = null,
        ?string $legacySecondLocalKey = null
    ): HasManyThrough {
        $isLegacy = false;
        $name     = null;

        if ($through !== null && $this->isModelReference($through) && ! $this->isModelReference($related)) {
            $isLegacy       = true;
            $name           = (string) $related;
            $related        = $through;
            $through        = $firstKey;
            $firstKey       = $secondKey;
            $secondKey      = $localKey;
            $localKey       = $secondLocalKey;
            $secondLocalKey = $legacySecondLocalKey;
        } else {
            $name = $this->guessRelationName();
        }

        $relatedClass = $this->normalizeModelClass($related);
        $throughClass = $this->normalizeModelClass($through);

        $throughModel = Relation::createModelInstance($throughClass, $this);
        $throughTable = Relation::getModelTable($throughModel);

        $localKey       = $localKey ?: ($this->primaryKey ?: 'id');
        $secondLocalKey = $secondLocalKey ?: Relation::getModelPrimaryKey($throughModel);
        $firstKey       = $firstKey ?: (Relation::singularize((string) $this->table) . '_' . $localKey);
        $secondKey      = $secondKey ?: (Relation::singularize($throughTable) . '_' . $secondLocalKey);

        $relation = new HasManyThrough(
            $this,
            $relatedClass,
            $throughClass,
            $firstKey,
            $secondKey,
            $localKey,
            $secondLocalKey,
            $name
        );

        if ($isLegacy) {
            $this->relations[$name] = $relation;
        }

        return $relation;
    }

    /**
     * Define a polymorphic one-to-one relationship.
     *
     * @param string|object $related
     */
    public function morphOne(
        $related,
        string $name,
        ?string $type = null,
        ?string $id = null,
        ?string $localKey = null,
        ?string $legacyLocalKey = null
    ): MorphOne {
        $isLegacy     = false;
        $relationName = null;

        if ($this->isModelReference($name) && ! $this->isModelReference($related)) {
            $isLegacy     = true;
            $relationName = (string) $related;
            $related      = $name;
            $name         = (string) $type;
            $type         = $id;
            $id           = $localKey;
            $localKey     = $legacyLocalKey;
        } else {
            $relationName = $this->guessRelationName();
        }

        $modelClass = $this->normalizeModelClass($related);
        $type       = $type ?: ($name . '_type');
        $id         = $id ?: ($name . '_id');
        $localKey   = $localKey ?: ($this->primaryKey ?: 'id');

        $relation = new MorphOne($this, $modelClass, $type, $id, $localKey, $relationName);

        if ($isLegacy) {
            $this->relations[$relationName] = $relation;
        }

        return $relation;
    }

    /**
     * Define a polymorphic one-to-many relationship.
     *
     * @param string|object $related
     */
    public function morphMany(
        $related,
        string $name,
        ?string $type = null,
        ?string $id = null,
        ?string $localKey = null,
        ?string $legacyLocalKey = null
    ): MorphMany {
        $isLegacy     = false;
        $relationName = null;

        if ($this->isModelReference($name) && ! $this->isModelReference($related)) {
            $isLegacy     = true;
            $relationName = (string) $related;
            $related      = $name;
            $name         = (string) $type;
            $type         = $id;
            $id           = $localKey;
            $localKey     = $legacyLocalKey;
        } else {
            $relationName = $this->guessRelationName();
        }

        $modelClass = $this->normalizeModelClass($related);
        $type       = $type ?: ($name . '_type');
        $id         = $id ?: ($name . '_id');
        $localKey   = $localKey ?: ($this->primaryKey ?: 'id');

        $relation = new MorphMany($this, $modelClass, $type, $id, $localKey, $relationName);

        if ($isLegacy) {
            $this->relations[$relationName] = $relation;
        }

        return $relation;
    }

    /**
     * Define a polymorphic inverse one-to-one / one-to-many relationship.
     */
    public function morphTo(
        ?string $name = null,
        ?string $type = null,
        ?string $id = null,
        ?string $ownerKey = null
    ): MorphTo {
        $relationName = $name ?: $this->guessRelationName();
        $morphName    = Relation::snakeCase($relationName);
        $type         = $type ?: ($morphName . '_type');
        $id           = $id ?: ($morphName . '_id');

        return new MorphTo($this, $id, $type, $ownerKey, $relationName);
    }

    /**
     * Define a polymorphic many-to-many relationship.
     *
     * @param string|object $related
     */
    public function morphToMany(
        $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        bool $inverse = false
    ): MorphToMany {
        $relationName = $this->guessRelationName();
        $modelClass   = $this->normalizeModelClass($related);
        $relatedModel = Relation::createModelInstance($modelClass, $this);
        $relatedTable = Relation::getModelTable($relatedModel);

        $table           = $table ?: Relation::pluralize($name);
        $parentKey       = $parentKey ?: ($this->primaryKey ?: 'id');
        $relatedKey      = $relatedKey ?: Relation::getModelPrimaryKey($relatedModel);
        $foreignPivotKey = $foreignPivotKey ?: ($inverse ? (Relation::singularize((string) $this->table) . '_' . $parentKey) : ($name . '_id'));
        $relatedPivotKey = $relatedPivotKey ?: ($inverse ? ($name . '_id') : (Relation::singularize($relatedTable) . '_' . $relatedKey));

        return new MorphToMany(
            $this,
            $modelClass,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse
        );
    }

    /**
     * Define the inverse of a polymorphic many-to-many relationship.
     *
     * @param string|object $related
     */
    public function morphedByMany(
        $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): MorphToMany {
        return $this->morphToMany(
            $related,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            true
        );
    }

    // =========================================================================
    // Eager Loading API (with, without, withoutRelations, load, loadMissing)
    // =========================================================================

    /**
     * Set relationships to eager load with the next query.
     *
     * Examples:
     *   ->with('posts')
     *   ->with('posts', 'profile')
     *   ->with(['posts', 'profile'])
     *   ->with('posts', fn($q) => $q->where('published', 1))
     *   ->with(['posts' => fn($q) => $q->where('published', 1), 'posts.comments.author'])
     *   ->with('posts:id,user_id,title')
     *
     * @param string|array<int|string, mixed> $relations
     * @param string|callable|null            $clause
     */
    public function with($relations, $clause = null): self
    {
        $this->bootRelationships();

        if (is_string($relations) && is_callable($clause) && func_num_args() === 2) {
            $parsed = $this->parseWithRelations([$relations => $clause]);
        } elseif (is_array($relations)) {
            $parsed = $this->parseWithRelations($relations);
        } else {
            $parsed = $this->parseWithRelations(func_get_args());
        }

        $this->activeRelations = $this->mergeRelationTrees($this->activeRelations, $parsed);

        return $this;
    }

    /**
     * Merge an already-parsed eager-load tree into activeRelations (used for nested dot notation).
     *
     * @param array<string, array> $tree
     */
    public function withTree(array $tree): self
    {
        $this->bootRelationships();
        $this->activeRelations = $this->mergeRelationTrees($this->activeRelations, $tree);

        return $this;
    }

    /**
     * Prevent one or more default ($with) relationships from eager loading on the next query.
     *
     * @param string|array<int, string> $relations
     */
    public function without($relations): self
    {
        $list = is_array($relations) ? $relations : func_get_args();
        foreach ($list as $name) {
            $this->withoutRelations[(string) $name] = true;
            unset($this->activeRelations[(string) $name]);
        }

        return $this;
    }

    /**
     * Disable all default ($with / $withCount) eager loads for the next query.
     */
    public function withoutRelations(): self
    {
        $this->disableDefaultRelations = true;
        $this->activeRelations         = [];
        $this->activeAggregates        = [];

        return $this;
    }

    /**
     * Eager load relationships onto an already-fetched row/entity or array of rows/entities.
     *
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     * @param string|callable|null            $clause
     *
     * @return array|object
     */
    public function load(&$rows, $relations, $clause = null)
    {
        if ($rows === null || $rows === []) {
            return $rows;
        }

        if (is_string($relations) && is_callable($clause) && func_num_args() === 3) {
            $tree = $this->parseWithRelations([$relations => $clause]);
        } elseif (is_array($relations)) {
            $tree = $this->parseWithRelations($relations);
        } else {
            $args = func_get_args();
            array_shift($args);
            $tree = $this->parseWithRelations($args);
        }

        $isSingle = $this->isSingleRow($rows);
        if ($isSingle) {
            $rowList = [&$rows];
            $this->eagerLoadTree($rowList, $tree);
        } else {
            $this->eagerLoadTree($rows, $tree);
        }

        return $rows;
    }

    /**
     * Eager load relationships onto already-fetched rows/entities only if not already loaded.
     *
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     * @param string|callable|null            $clause
     *
     * @return array|object
     */
    public function loadMissing(&$rows, $relations, $clause = null)
    {
        if ($rows === null || $rows === []) {
            return $rows;
        }

        if (is_string($relations) && is_callable($clause) && func_num_args() === 3) {
            $tree = $this->parseWithRelations([$relations => $clause]);
        } elseif (is_array($relations)) {
            $tree = $this->parseWithRelations($relations);
        } else {
            $args = func_get_args();
            array_shift($args);
            $tree = $this->parseWithRelations($args);
        }

        $isSingle = $this->isSingleRow($rows);
        if ($isSingle) {
            $rowList = [&$rows];
        } else {
            $rowList = &$rows;
        }

        foreach ($tree as $name => $config) {
            $missingIndices = [];
            foreach ($rowList as $idx => &$row) {
                if (! $this->rowHasRelationLoaded($row, $name)) {
                    $missingIndices[] = $idx;
                } elseif ($config['nested'] !== []) {
                    $existing = Relation::getRowValue($row, $name);
                    if ($existing !== null && $existing !== []) {
                        $relInstance = $this->resolveRelation($name);
                        $relModel    = Relation::createModelInstance($relInstance->getRelatedClass(), $this);
                        if (method_exists($relModel, 'loadMissing')) {
                            $relModel->loadMissing($existing, $this->treeToWithArray($config['nested']));
                            Relation::setRowRelation($row, $name, $existing);
                        }
                    }
                }
            }
            unset($row);

            if ($missingIndices !== []) {
                $reindexed = [];
                foreach ($missingIndices as $idx) {
                    $reindexed[] = &$rowList[$idx];
                }
                $this->eagerLoadTree($reindexed, [$name => $config]);
            }
        }

        return $rows;
    }

    // =========================================================================
    // Aggregate Eager Loading (withCount, withSum, withAvg, withMin, withMax, withExists)
    // =========================================================================

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withCount($relations): self
    {
        return $this->withAggregate($relations, '*', 'count');
    }

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withSum($relations, string $column): self
    {
        return $this->withAggregate($relations, $column, 'sum');
    }

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withAvg($relations, string $column): self
    {
        return $this->withAggregate($relations, $column, 'avg');
    }

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withMin($relations, string $column): self
    {
        return $this->withAggregate($relations, $column, 'min');
    }

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withMax($relations, string $column): self
    {
        return $this->withAggregate($relations, $column, 'max');
    }

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withExists($relations): self
    {
        return $this->withAggregate($relations, '*', 'exists');
    }

    /**
     * @param string|array<int|string, mixed> $relations
     */
    public function withAggregate($relations, string $column, ?string $function = null): self
    {
        $this->bootRelationships();
        $items = is_array($relations) ? $relations : [$relations];

        foreach ($this->parseAggregateItems($items, $column, $function) as $item) {
            $this->activeAggregates[] = $item;
        }

        return $this;
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadCount(&$rows, $relations)
    {
        return $this->loadAggregate($rows, $relations, '*', 'count');
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadSum(&$rows, $relations, string $column)
    {
        return $this->loadAggregate($rows, $relations, $column, 'sum');
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadAvg(&$rows, $relations, string $column)
    {
        return $this->loadAggregate($rows, $relations, $column, 'avg');
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadMin(&$rows, $relations, string $column)
    {
        return $this->loadAggregate($rows, $relations, $column, 'min');
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadMax(&$rows, $relations, string $column)
    {
        return $this->loadAggregate($rows, $relations, $column, 'max');
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadExists(&$rows, $relations)
    {
        return $this->loadAggregate($rows, $relations, '*', 'exists');
    }

    /**
     * @param array|object                    $rows
     * @param string|array<int|string, mixed> $relations
     *
     * @return array|object
     */
    public function loadAggregate(&$rows, $relations, string $column, ?string $function = null)
    {
        if ($rows === null || $rows === []) {
            return $rows;
        }

        $items    = is_array($relations) ? $relations : [$relations];
        $parsed   = $this->parseAggregateItems($items, $column, $function);
        $isSingle = $this->isSingleRow($rows);
        if ($isSingle) {
            $rowList = [&$rows];
        } else {
            $rowList = &$rows;
        }

        foreach ($parsed as $agg) {
            $relation = $this->resolveRelation($agg['relation']);
            $relation->loadAggregate(
                $rowList,
                $agg['alias'],
                $agg['function'],
                $agg['column'],
                $agg['callback']
            );
        }

        return $rows;
    }

    // =========================================================================
    // Existence Queries (has, doesntHave, whereHas, whereDoesntHave, etc.)
    // =========================================================================

    public function has(
        string $relation,
        string $operator = '>=',
        int $count = 1,
        string $boolean = 'AND',
        ?callable $callback = null
    ): self {
        if (strpos($relation, '.') !== false) {
            return $this->hasNested($relation, $operator, $count, $boolean, $callback);
        }

        $rel = $this->resolveRelation($relation);

        if ($operator === '>=' && $count === 1) {
            $subquery = $rel->getRelationExistenceQuery($this, (string) $this->table, $callback, '1');
            Relation::applyRawWhere($this->builder(), "EXISTS ({$subquery})", $boolean);

            return $this;
        }

        if ($operator === '<' && $count === 1) {
            $subquery = $rel->getRelationExistenceQuery($this, (string) $this->table, $callback, '1');
            Relation::applyRawWhere($this->builder(), "NOT EXISTS ({$subquery})", $boolean);

            return $this;
        }

        $subquery = $rel->getRelationExistenceQuery($this, (string) $this->table, $callback, 'COUNT(*)');
        $allowed  = ['=', '!=', '<>', '<', '<=', '>', '>='];
        if (! in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Invalid comparison operator [%s] for has().', $operator));
        }

        Relation::applyRawWhere($this->builder(), "({$subquery}) {$operator} " . (int) $count, $boolean);

        return $this;
    }

    public function orHas(string $relation, string $operator = '>=', int $count = 1): self
    {
        return $this->has($relation, $operator, $count, 'OR');
    }

    public function doesntHave(string $relation, string $boolean = 'AND', ?callable $callback = null): self
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    public function orDoesntHave(string $relation): self
    {
        return $this->doesntHave($relation, 'OR');
    }

    public function whereHas(
        string $relation,
        ?callable $callback = null,
        string $operator = '>=',
        int $count = 1
    ): self {
        return $this->has($relation, $operator, $count, 'AND', $callback);
    }

    public function orWhereHas(
        string $relation,
        ?callable $callback = null,
        string $operator = '>=',
        int $count = 1
    ): self {
        return $this->has($relation, $operator, $count, 'OR', $callback);
    }

    public function whereDoesntHave(string $relation, ?callable $callback = null): self
    {
        return $this->doesntHave($relation, 'AND', $callback);
    }

    public function orWhereDoesntHave(string $relation, ?callable $callback = null): self
    {
        return $this->doesntHave($relation, 'OR', $callback);
    }

    protected function hasNested(
        string $relations,
        string $operator = '>=',
        int $count = 1,
        string $boolean = 'AND',
        ?callable $callback = null
    ): self {
        $segments = explode('.', $relations);
        $first    = array_shift($segments);
        $rest     = implode('.', $segments);

        return $this->has($first, $operator, $count, $boolean, static function ($query) use ($rest, $callback) {
            $query->whereHas($rest, $callback);
        });
    }

    // =========================================================================
    // Model Callbacks: beforeFind (Aggregates) & afterFind (Eager Loading)
    // =========================================================================

    /**
     * beforeFind callback: injects SQL subqueries for withCount/withSum/withAvg/withMin/withMax/withExists.
     */
    protected function applyRelationshipAggregates(array $data): array
    {
        $aggregates = $this->activeAggregates;
        if (! $this->disableDefaultRelations && property_exists($this, 'withCount') && is_array($this->withCount) && $this->withCount !== []) {
            $defaultAggs = $this->parseAggregateItems($this->withCount, '*', 'count');
            $aggregates  = array_merge($defaultAggs, $aggregates);
        }

        $this->activeAggregates      = [];
        $this->pendingAggregateCasts = [];

        if ($aggregates === []) {
            return $data;
        }

        $builder  = $this->builder();
        $qbSelect = Relation::getObjectProperty($builder, 'QBSelect');
        if (! is_array($qbSelect) || $qbSelect === []) {
            $this->select($this->table . '.*');
        }

        foreach ($aggregates as $agg) {
            $relation = $this->resolveRelation($agg['relation']);
            $fn       = strtoupper($agg['function']);

            if ($fn === 'EXISTS') {
                $subquery = $relation->getRelationExistenceQuery(
                    $this,
                    (string) $this->table,
                    $agg['callback'],
                    '1'
                );
                $sql = 'EXISTS (' . $subquery . ')';
            } else {
                $relatedTable = Relation::getModelTable($relation->getRelated());
                $col          = $agg['column'];
                $aggCol       = $col === '*'
                    ? '*'
                    : (strpos($col, '.') === false ? $this->db->protectIdentifiers($relatedTable . '.' . $col) : $col);
                $selectExpr   = sprintf('%s(%s)', $fn, $aggCol);

                $subquery = $relation->getRelationExistenceQuery(
                    $this,
                    (string) $this->table,
                    $agg['callback'],
                    $selectExpr
                );
                $sql = '(' . $subquery . ')';
            }

            $protectedAlias = $this->db->protectIdentifiers($agg['alias']);
            $this->select($sql . ' AS ' . $protectedAlias, false);
            $this->pendingAggregateCasts[$agg['alias']] = $fn;
        }

        return $data;
    }

    /**
     * afterFind callback: attaches Entity model bindings, casts aggregates, and executes eager loads.
     */
    protected function applyRelations(array $data): array
    {
        $activeRelations         = $this->activeRelations;
        $withoutRelations        = $this->withoutRelations;
        $disableDefaultRelations = $this->disableDefaultRelations;
        $aggregateCasts          = $this->pendingAggregateCasts;

        // Reset per-query state BEFORE running any nested queries (prevents re-entrancy bugs)
        $this->activeRelations         = [];
        $this->withoutRelations        = [];
        $this->disableDefaultRelations = false;
        $this->pendingAggregateCasts   = [];

        if (empty($data['data'])) {
            return $data;
        }

        $defaultTree = [];
        if (
            ! $disableDefaultRelations
            && empty(static::$resolvingDefaultWith[static::class])
            && property_exists($this, 'with')
            && is_array($this->with)
            && $this->with !== []
        ) {
            $defaultTree = $this->parseWithRelations($this->with);
            foreach (array_keys($withoutRelations) as $excluded) {
                unset($defaultTree[$excluded]);
            }
        }

        $tree = $this->mergeRelationTrees($defaultTree, $activeRelations);

        $isSingleton = ! empty($data['singleton']);
        if ($isSingleton) {
            $rows = [&$data['data']];
        } else {
            $rows = &$data['data'];
        }

        // Bind model class onto EntityRelationshipsTrait entities & normalize aggregate casts
        foreach ($rows as &$row) {
            if (is_object($row) && method_exists($row, 'setModelClass')) {
                $row->setModelClass(static::class, $this->db);
            }

            foreach ($aggregateCasts as $alias => $fn) {
                $rawVal = Relation::getRowValue($row, $alias);
                if ($fn === 'COUNT') {
                    Relation::setRowValue($row, $alias, (int) ($rawVal ?? 0));
                } elseif ($fn === 'EXISTS') {
                    Relation::setRowValue($row, $alias, (bool) $rawVal);
                } elseif ($fn === 'AVG' && $rawVal !== null) {
                    Relation::setRowValue($row, $alias, (float) $rawVal);
                } elseif ($fn === 'SUM' && $rawVal !== null && is_numeric($rawVal)) {
                    Relation::setRowValue(
                        $row,
                        $alias,
                        strpos((string) $rawVal, '.') !== false ? (float) $rawVal : (int) $rawVal
                    );
                }
                Relation::syncRowOriginalKey($row, $alias);
            }
        }
        unset($row);

        if ($tree === []) {
            return $data;
        }

        $pushedDefaultGuard = false;
        if ($defaultTree !== [] && empty(static::$resolvingDefaultWith[static::class])) {
            static::$resolvingDefaultWith[static::class] = true;
            $pushedDefaultGuard                          = true;
        }

        try {
            $this->eagerLoadTree($rows, $tree);
        } finally {
            if ($pushedDefaultGuard) {
                unset(static::$resolvingDefaultWith[static::class]);
            }
        }

        return $data;
    }

    // =========================================================================
    // Internal Resolution & Parsing Helpers
    // =========================================================================

    /**
     * Check whether a relationship named $name exists on this model.
     */
    public function hasRelation(string $name): bool
    {
        if (isset($this->relations[$name])) {
            return true;
        }

        if (! method_exists($this, $name)) {
            return false;
        }

        $ref = new \ReflectionMethod($this, $name);
        if ($ref->isStatic() || $ref->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $declaringClass = $ref->getDeclaringClass()->getName();
        if ($declaringClass === Model::class || $declaringClass === \CodeIgniter\BaseModel::class) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a fresh Relation instance by name.
     */
    public function resolveRelation(string $name): Relation
    {
        if (isset($this->relations[$name])) {
            return clone $this->relations[$name];
        }

        if (! method_exists($this, $name)) {
            throw new InvalidArgumentException(sprintf(
                'Undefined relationship [%s] on model [%s].',
                $name,
                static::class
            ));
        }

        $this->resolvingRelationName = $name;
        try {
            $relation = $this->{$name}();
        } finally {
            $this->resolvingRelationName = null;
        }

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException(sprintf(
                'Relationship method [%s::%s()] must return an instance of %s.',
                static::class,
                $name,
                Relation::class
            ));
        }

        return $relation;
    }

    /**
     * Execute eager loading for a parsed relation tree against $rows.
     *
     * @param array<int, array|object> $rows
     * @param array<string, array>     $tree
     */
    protected function eagerLoadTree(array &$rows, array $tree): void
    {
        foreach ($tree as $name => $config) {
            $relation = $this->resolveRelation($name);

            if ($config['columns'] !== null) {
                $relation->setSelectedColumns($config['columns']);
            }

            foreach ($config['constraints'] as $constraint) {
                $constraint($relation);
            }

            $relation->eagerLoad($rows, $config['nested']);
        }
    }

    /**
     * Parse Laravel-style with() arguments into a recursive eager-load tree.
     *
     * @param array<int|string, mixed> $relations
     *
     * @return array<string, array{columns: array<int, string>|null, constraints: array<int, callable>, nested: array}>
     */
    protected function parseWithRelations(array $relations): array
    {
        $tree = [];

        foreach ($relations as $key => $value) {
            if (is_int($key)) {
                $name    = (string) $value;
                $closure = null;
            } else {
                $name    = (string) $key;
                $closure = is_callable($value) ? $value : null;
            }

            $segments = explode('.', $name);
            $current  = &$tree;

            $lastIndex = count($segments) - 1;
            foreach ($segments as $idx => $segment) {
                $columns = null;
                if (strpos($segment, ':') !== false) {
                    [$segment, $colStr] = explode(':', $segment, 2);
                    $columns            = array_values(array_filter(array_map('trim', explode(',', $colStr))));
                }

                if (! isset($current[$segment])) {
                    $current[$segment] = [
                        'columns'     => null,
                        'constraints' => [],
                        'nested'      => [],
                    ];
                }

                if ($columns !== null) {
                    $current[$segment]['columns'] = $columns;
                }

                if ($idx === $lastIndex && $closure !== null) {
                    $current[$segment]['constraints'][] = $closure;
                }

                $current = &$current[$segment]['nested'];
            }
            unset($current);
        }

        return $tree;
    }

    /**
     * Recursively merge two parsed eager-load trees.
     *
     * @param array<string, array> $base
     * @param array<string, array> $incoming
     *
     * @return array<string, array>
     */
    protected function mergeRelationTrees(array $base, array $incoming): array
    {
        foreach ($incoming as $name => $config) {
            if (! isset($base[$name])) {
                $base[$name] = $config;
                continue;
            }

            if ($config['columns'] !== null) {
                $base[$name]['columns'] = $config['columns'];
            }

            if ($config['constraints'] !== []) {
                $base[$name]['constraints'] = array_merge($base[$name]['constraints'], $config['constraints']);
            }

            if ($config['nested'] !== []) {
                $base[$name]['nested'] = $this->mergeRelationTrees($base[$name]['nested'], $config['nested']);
            }
        }

        return $base;
    }

    /**
     * Convert a parsed tree back into an array compatible with loadMissing().
     *
     * @param array<string, array> $tree
     *
     * @return array<string, callable|string>
     */
    protected function treeToWithArray(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $name => $config) {
            $full = $prefix !== '' ? ($prefix . '.' . $name) : $name;
            if ($config['columns'] !== null) {
                $fullWithCols = $full . ':' . implode(',', $config['columns']);
            } else {
                $fullWithCols = $full;
            }

            if ($config['constraints'] !== []) {
                $constraints        = $config['constraints'];
                $out[$fullWithCols] = static function ($q) use ($constraints) {
                    foreach ($constraints as $fn) {
                        $fn($q);
                    }
                };
            } elseif ($config['nested'] === []) {
                $out[] = $fullWithCols;
            }

            if ($config['nested'] !== []) {
                $out = array_merge($out, $this->treeToWithArray($config['nested'], $full));
            }
        }

        return $out;
    }

    /**
     * Parse aggregate specifications (e.g. "posts", "posts as total", ["posts" => fn]).
     *
     * @param array<int|string, mixed> $items
     *
     * @return array<int, array{relation: string, alias: string, function: string, column: string, callback: callable|null}>
     */
    protected function parseAggregateItems(array $items, string $column, ?string $function): array
    {
        $fn     = strtolower($function ?: 'count');
        $parsed = [];

        foreach ($items as $key => $value) {
            if (is_int($key)) {
                $expr     = (string) $value;
                $callback = null;
            } else {
                $expr     = (string) $key;
                $callback = is_callable($value) ? $value : null;
            }

            $alias = null;
            if (preg_match('/\s+as\s+/i', $expr)) {
                [$relationName, $alias] = preg_split('/\s+as\s+/i', $expr, 2);
                $relationName           = trim($relationName);
                $alias                  = trim($alias);
            } else {
                $relationName = trim($expr);
            }

            if ($alias === null || $alias === '') {
                $base  = Relation::snakeCase($relationName);
                $alias = $column === '*'
                    ? ($base . '_' . $fn)
                    : ($base . '_' . $fn . '_' . $column);
            }

            $parsed[] = [
                'relation' => $relationName,
                'alias'    => $alias,
                'function' => $fn,
                'column'   => $column,
                'callback' => $callback,
            ];
        }

        return $parsed;
    }

    /**
     * Guess the calling relationship method name when not explicitly provided.
     */
    protected function guessRelationName(): string
    {
        if ($this->resolvingRelationName !== null) {
            return $this->resolvingRelationName;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        foreach ($trace as $frame) {
            $func = $frame['function'] ?? '';
            if (
                $func !== ''
                && $func !== 'guessRelationName'
                && ! in_array($func, [
                    'hasOne', 'hasMany', 'belongsTo', 'belongsToMany',
                    'hasOneThrough', 'hasManyThrough',
                    'morphOne', 'morphMany', 'morphTo', 'morphToMany', 'morphedByMany',
                ], true)
            ) {
                return $func;
            }
        }

        return '';
    }

    /**
     * Check whether an argument looks like a Model class/object/short-name rather than a relation field name.
     *
     * @param mixed $value
     */
    protected function isModelReference($value): bool
    {
        if (is_object($value)) {
            return true;
        }

        if (! is_string($value) || $value === '') {
            return false;
        }

        if (class_exists($value)) {
            return true;
        }

        if (strpos($value, '\\') !== false) {
            return true;
        }

        return substr($value, -5) === 'Model';
    }

    /**
     * Normalize a model class string or object to a class name string.
     *
     * @param string|object $model
     */
    protected function normalizeModelClass($model): string
    {
        return is_object($model) ? get_class($model) : (string) $model;
    }

    /**
     * Compute Laravel-style alphabetical pivot table name from two table names.
     */
    protected function joiningTable(string $tableA, string $tableB): string
    {
        $segments = [
            Relation::singularize($tableA),
            Relation::singularize($tableB),
        ];

        sort($segments, SORT_STRING);

        return implode('_', $segments);
    }

    /**
     * Determine whether $value represents a single row (entity/stdClass/assoc array) vs a list of rows.
     *
     * @param mixed $value
     */
    protected function isSingleRow($value): bool
    {
        if (is_object($value)) {
            return true;
        }

        if (! is_array($value) || $value === []) {
            return false;
        }

        return $this->isAssociativeArray($value);
    }

    /**
     * @param array<mixed> $array
     */
    protected function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if a single row already has a relation loaded.
     *
     * @param array|object $row
     */
    protected function rowHasRelationLoaded($row, string $name): bool
    {
        if (is_array($row)) {
            return array_key_exists($name, $row);
        }

        if (is_object($row) && method_exists($row, 'relationLoaded')) {
            return $row->relationLoaded($name);
        }

        if ($row instanceof \CodeIgniter\Entity\Entity) {
            $attrs = Relation::getObjectProperty($row, 'attributes');

            return is_array($attrs) && array_key_exists($name, $attrs);
        }

        if (is_object($row)) {
            return property_exists($row, $name);
        }

        return false;
    }

    /**
     * Backwards-compatible helper to guess a foreign key from a related model.
     */
    protected function getRelationForeignKey(Model $model): string
    {
        return sprintf('%s_%s', Relation::singularize((string) $this->table), $this->primaryKey ?: 'id');
    }
}
