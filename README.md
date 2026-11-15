# CodeIgniter 4 Database Relationships

A Laravel Eloquent-style relationships package for CodeIgniter 4 Models and Entities.

CodeIgniter 4 ships with `Model` and `Entity`, but leaves relationship wiring, eager loading, pivot tables, polymorphic associations, existence filtering, and aggregates to manual joins and repetitive queries. This package adds an expressive, Laravel-compatible relationship layer directly onto standard CodeIgniter 4 `Model` and `Entity` classes—without replacing CI4's query builder, validation, casts, or soft deletes.

---

## Table of Contents

- [Beginner's Mental Model](#beginners-mental-model)
- [Why This Update Exists (What Changed & Why)](#why-this-update-exists-what-changed--why)
- [Installation & Quick Start](#installation--quick-start)
- [Upgrading from v1.x (Namespace & Legacy Bridge)](#upgrading-from-v1x-namespace--legacy-bridge)
- [1. Defining Relationships on Models (`RelationshipsTrait`)](#1-defining-relationships-on-models-relationshipstrait)
- [2. Eager Loading & Lazy Eager Loading](#2-eager-loading--lazy-eager-loading)
- [3. Querying Relationship Existence](#3-querying-relationship-existence)
- [4. Relationship Aggregates (Counts, Sums, Averages, Exists)](#4-relationship-aggregates-counts-sums-averages-exists)
- [5. Relation Modifiers (Defaults, One-of-Many, Pivot & Morph Options)](#5-relation-modifiers-defaults-one-of-many-pivot--morph-options)
- [6. Querying & Writing Directly Through a Relation](#6-querying--writing-directly-through-a-relation)
- [7. Polymorphic Morph Maps](#7-polymorphic-morph-maps)
- [8. Entity Support (`EntityRelationshipsTrait`)](#8-entity-support-entityrelationshipstrait)
- [9. Inspection & Utility Methods](#9-inspection--utility-methods)
- [Testing](#testing)

---

## Beginner's Mental Model

If you are new to ORM relationships, here are the only four concepts you need:

1. **Relationship method**: A method on your `Model` (like `public function posts()`) that describes how this table connects to another table.
2. **Eager loading (`with()`)**: Instead of running 1 query to get 50 users and then 50 separate queries to get each user's posts (the **N+1 problem**), `with('posts')` fetches all 50 users in **1 query** and all their posts in **1 `WHERE user_id IN (...)` query**, then stitches them together automatically.
3. **Existence queries (`has()` / `whereHas()`)**: Filter the *parent* table based on its children (e.g., *"only give me users who have at least 1 published post"*).
4. **Entity vs Array results**:
   - If your Model returns `array` or `stdClass`, use `with(...)` to eager load relations, or `$model->posts()->for($user)->...` when querying/writing for a specific row.
   - If your Model returns an `Entity` using `EntityRelationshipsTrait`, you can also do `$user->posts` (lazy property access) and `$user->posts()->create(...)` directly on the entity.

---

## Why This Update Exists (What Changed & Why)

Earlier versions of this package (`v1.x`) only supported basic `hasOne`/`hasMany` inside `initialize()` and had several hidden pitfalls in real applications:

1. **Method-based relationships now work (plus `initialize()` backwards compatibility)**  
   *Why:* Previously, `with()` failed unless a relation existed in both `$this->relations` *and* as a method. Now standard Laravel-style methods (`public function posts() { return $this->hasMany(...); }`) work seamlessly alongside legacy `initialize()` calls.
2. **Isolated non-shared Model instances (no query builder bleed)**  
   *Why:* CodeIgniter's `model($class)` returns a shared singleton by default. Reusing a shared instance across nested or consecutive eager loads leaked `where`/`select`/`orderBy` clauses between queries. Every relation now spawns a fresh non-shared model instance while reusing the parent's DB connection.
3. **Automatic `orWhere` grouping inside constraints**  
   *Why:* Calling `->orWhere(...)` inside a `with(['posts' => fn ($q) => ...])` closure previously broke SQL operator precedence (`WHERE user_id IN (...) AND a = 1 OR b = 2`), returning rows that belonged to *other* parents. Any constraint containing `OR` is now wrapped in parentheses automatically.
4. **Entity `save()` and `hasChanged()` safety**  
   *Why:* Assigning eager-loaded relations onto a `CodeIgniter\Entity\Entity` previously dirtied `$attributes`, making `$entity->hasChanged()` return `true` and causing `$model->save($entity)` to crash trying to save relation arrays into DB columns. Relations are now kept separate from `$attributes` (via `EntityRelationshipsTrait::$_relations` or synced `$original` on plain entities).
5. **Consistent return types, empty values, UUIDs, and foreign key guessing**  
   *Why:* Fixed swapped `$foreignKey`/`$localKey` arguments, fixed key guessing to use the parent's `$primaryKey` (including UUID/string primary keys), normalized missing `hasOne` results to `null` (not `[]`), and prevented invalid `WHERE IN ()` SQL when parent IDs are empty or `null`.
6. **Per-query state cleanup & circular `$with` guard**  
   *Why:* Active `with()`, `without()`, and aggregate state is reset *before* running child queries so nested loads never inherit leftover flags, and default `$with` arrays guard against infinite loops on mutual relationships (`User -> Profile -> User`).
7. **Consistent package namespace (`IsaacMachakata\Ci4Relationships`) with `v1.x` compatibility bridge**  
   *Why:* Moves away from the generic `CI4Extensions\Database` namespace to `IsaacMachakata\Ci4Relationships` to match the rest of the `IsaacMachakata\Ci4*` ecosystem, while keeping a deprecated `CI4Extensions\Database` trait bridge during `v2.x` so existing projects don't break on upgrade.

---

## Installation & Quick Start

```bash
composer require immachakata/ci4-relationships
```

```php
<?php

namespace App\Models;

use CodeIgniter\Model;
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;

class UserModel extends Model
{
    use RelationshipsTrait;

    protected $table      = 'users';
    protected $primaryKey = 'id';

    public function profile()
    {
        return $this->hasOne(ProfileModel::class);
    }

    public function posts()
    {
        return $this->hasMany(PostModel::class);
    }
}

// Fetch users with their profile and posts in 3 queries total (no N+1)
$users = model(UserModel::class)->with('profile', 'posts')->findAll();
```

---

## Upgrading from v1.x (Namespace & Legacy Bridge)

The primary namespace is now **`IsaacMachakata\Ci4Relationships`** (instead of `CI4Extensions\Database`):

```php
// Before (v1.x)
use CI4Extensions\Database\RelationshipsTrait;

// Now (v2.x+)
use IsaacMachakata\Ci4Relationships\RelationshipsTrait;
use IsaacMachakata\Ci4Relationships\EntityRelationshipsTrait;
use IsaacMachakata\Ci4Relationships\Relations\Relation;
use IsaacMachakata\Ci4Relationships\Relations\Pivot;
```

- **Zero-breakage bridge**: Deprecated bridge traits for `CI4Extensions\Database\RelationshipsTrait` and `CI4Extensions\Database\EntityRelationshipsTrait` are included throughout `v2.x` so existing imports continue working immediately while you migrate (scheduled for removal in `v3.0.0`).
- **Legacy `initialize()` syntax**: Existing models that register relationships inside `initialize()` continue to work unchanged:
  ```php
  public function initialize()
  {
      $this->hasOne('avatar', AvatarModel::class, 'user_id');
      $this->hasMany('links', LinkModel::class, 'user_id');
  }
  ```

---

## 1. Defining Relationships on Models (`RelationshipsTrait`)

Add `use RelationshipsTrait;` to your `CodeIgniter\Model` subclass and return one of the relationship definitions below.

---

### `hasOne($related, ?string $foreignKey = null, ?string $localKey = null)`
Defines a **one-to-one** relationship where the *related* table holds the foreign key pointing back to this model (e.g., `profiles.user_id -> users.id`). Returns a `HasOne` instance. Resolves to `null` when no matching row exists (unless `withDefault()` is used).

- **Default keys**: `$localKey` defaults to `$this->primaryKey` (`'id'`); `$foreignKey` defaults to `singular($this->table) . '_' . $localKey` (`'user_id'`).
- **Example**:
  ```php
  public function profile()
  {
      return $this->hasOne(ProfileModel::class, 'user_id', 'id');
  }
  ```
- **Use case / Edge case**: Use when a parent may or may not have a single optional detail row (e.g., `User -> Profile`, `Order -> Invoice`). Even if the database accidentally contains duplicate child rows, `hasOne` attaches a single match; use `latestOfMany()` / `oldestOfMany()` when multiple rows exist and you want a specific winner.

---

### `hasMany($related, ?string $foreignKey = null, ?string $localKey = null)`
Defines a **one-to-many** relationship where each parent can own zero or more child rows (e.g., `posts.user_id -> users.id`). Returns a `HasMany` instance. Always resolves to an array (`[]` when empty).

- **Default keys**: Same as `hasOne`.
- **Example**:
  ```php
  public function posts()
  {
      return $this->hasMany(PostModel::class);
  }

  // You can also bake default filters or ordering into a dedicated relation method:
  public function publishedPosts()
  {
      return $this->hasMany(PostModel::class)
          ->where('status', 'published')
          ->orderBy('published_at', 'DESC');
  }
  ```
- **Use case / Edge case**: Safely handles parents with `0` children (returns `[]`), soft-deleted children (automatically excluded unless `withDeleted()` is applied), and string/UUID primary keys.

---

### `belongsTo($related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relationName = null)`
Defines the **inverse** of `hasOne` or `hasMany`—where *this* model holds the foreign key pointing to the parent/owner table (e.g., `posts.user_id -> users.id`). Returns a `BelongsTo` instance.

- **Default keys**: `$ownerKey` defaults to the related model's `$primaryKey`; `$foreignKey` defaults to `snakeCase($methodName) . '_' . $ownerKey` (so a method named `author()` guesses `author_id`, while `user()` guesses `user_id`).
- **Example**:
  ```php
  public function author()
  {
      // Method name is 'author', but column is 'user_id', so pass 'user_id' explicitly:
      return $this->belongsTo(UserModel::class, 'user_id', 'id');
  }
  ```
- **Use case / Edge case**: Ideal for self-referencing trees (`CategoryModel::parent()` -> `belongsTo(self::class, 'parent_id')`) and nullable foreign keys (e.g., `posts.user_id = NULL` for guest posts—returns `null` without crashing SQL `WHERE IN`, or a fallback via `withDefault()`).

---

### `belongsToMany($related, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null, ?string $relatedKey = null, ?string $relationName = null)`
Defines a **many-to-many** relationship connected through an intermediate (pivot) table (e.g., `users` <-> `role_user` <-> `roles`). Returns a `BelongsToMany` instance.

- **Default keys & table**:
  - `$table`: Alphabetical singular join of both table names (`roles` + `users` -> `'role_user'`).
  - `$foreignPivotKey`: `singular($this->table) . '_' . $parentKey` (`'user_id'`).
  - `$relatedPivotKey`: `singular($relatedTable) . '_' . $relatedKey` (`'role_id'`).
- **Example**:
  ```php
  public function roles()
  {
      return $this->belongsToMany(RoleModel::class, 'role_user', 'user_id', 'role_id')
          ->withPivot('assigned_by', 'role_label')
          ->withTimestamps();
  }
  ```
- **Use case / Edge case**: Automatically strips internal `__pivot_*` aliases and hydrates clean `pivot` data onto every related item—as an associative array on array models, or as a `Pivot` Entity (`$role->pivot->role_label` and `$role->pivot['role_label']`) on object/Entity models.

---

### `hasOneThrough($related, $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null)`
Defines a **distant one-to-one** relationship accessed through an intermediate model (e.g., `Country` -> `User` -> latest `Post`). Returns a `HasOneThrough` instance.

- **Key order**:
  - `$firstKey`: Column on `$through` pointing to `$this` (`'country_id'` on `users`).
  - `$secondKey`: Column on `$related` pointing to `$through` (`'user_id'` on `posts`).
  - `$localKey`: Primary/local key on `$this` (`'id'` on `countries`).
  - `$secondLocalKey`: Primary/local key on `$through` (`'id'` on `users`).
- **Example**:
  ```php
  public function latestPost()
  {
      return $this->hasOneThrough(PostModel::class, UserModel::class)
          ->orderBy('posts.id', 'DESC');
  }
  ```
- **Use case / Edge case**: Avoids manually loading intermediate rows just to grab one related record. Automatically excludes soft-deleted intermediate `$through` rows unless `->withDeletedParents()` is called.

---

### `hasManyThrough($related, $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null)`
Defines a **distant one-to-many** relationship through an intermediate model (e.g., all `Post`s in a `Country` through `User`). Returns a `HasManyThrough` instance.

- **Example**:
  ```php
  public function posts()
  {
      return $this->hasManyThrough(PostModel::class, UserModel::class);
  }
  ```
- **Use case / Edge case**: Works cleanly with `with()`, `withCount('posts')`, `has('posts')`, and `whereHas('posts', ...)`. Soft-deleted intermediate users are excluded automatically so deleted users' posts do not leak into country totals.

---

### `morphOne($related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null)`
Defines a **polymorphic one-to-one** relationship where a single shared table (e.g., `images`) can store one record for many different model types (`Post`, `User`, `Product`). Returns a `MorphOne` instance.

- **Default columns**: `$type` defaults to `"{$name}_type"` (`'imageable_type'`); `$id` defaults to `"{$name}_id"` (`'imageable_id'`).
- **Example**:
  ```php
  public function image()
  {
      return $this->morphOne(ImageModel::class, 'imageable');
  }
  ```
- **Use case / Edge case**: Supports `withDefault()`, `ofMany()`, `latestOfMany()`, and `oldestOfMany()` just like `hasOne`, while automatically filtering and writing `imageable_type`.

---

### `morphMany($related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null)`
Defines a **polymorphic one-to-many** relationship (e.g., both `PostModel` and `VideoModel` have many `CommentModel` rows stored in one `comments` table). Returns a `MorphMany` instance.

- **Example**:
  ```php
  public function comments()
  {
      return $this->morphMany(CommentModel::class, 'commentable');
  }
  ```
- **Use case / Edge case**: Creating a comment via `$post->comments()->create(['body' => 'Hi'])` automatically fills both `commentable_id` and `commentable_type` (respecting `Relation::morphMap()`).

---

### `morphTo(?string $name = null, ?string $type = null, ?string $id = null, ?string $ownerKey = null)`
Defines the **inverse polymorphic** relationship on the shared child model (e.g., `CommentModel::commentable()` returning either a `Post` or a `Video`). Returns a `MorphTo` instance.

- **Default columns**: `$name` defaults to the method name (`'commentable'`); `$type` defaults to `'commentable_type'`; `$id` defaults to `'commentable_id'`.
- **Example**:
  ```php
  // On CommentModel
  public function commentable()
  {
      return $this->morphTo();
  }
  ```
- **Use case / Edge case**: Groups all comments by `commentable_type` and executes **one query per distinct target model** instead of one query per comment. Note: SQL `has()`/`whereHas()` and `withCount()` are intentionally disallowed on `morphTo()` (throwing `BadMethodCallException`) because a single SQL subquery cannot target dynamic tables per row.

---

### `morphToMany($related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null, ?string $relatedKey = null)`
Defines a **polymorphic many-to-many** relationship (e.g., both `PostModel` and `VideoModel` sharing `TagModel` through a `taggables` table with `tag_id`, `taggable_id`, `taggable_type`). Returns a `MorphToMany` instance.

- **Default table/keys**: `$table` defaults to `plural($name)` (`'taggables'`); `$foreignPivotKey` defaults to `"{$name}_id"` (`'taggable_id'`); `$relatedPivotKey` defaults to `singular($relatedTable) . '_' . $relatedKey` (`'tag_id'`).
- **Example**:
  ```php
  // On PostModel & VideoModel
  public function tags()
  {
      return $this->morphToMany(TagModel::class, 'taggable');
  }
  ```
- **Use case / Edge case**: `attach()`, `detach()`, `sync()`, and `toggle()` automatically scope by `taggable_type`, so syncing tags on `Post #1` never deletes tags belonging to `Video #1`.

---

### `morphedByMany($related, string $name, ?string $table = null, ?string $foreignPivotKey = null, ?string $relatedPivotKey = null, ?string $parentKey = null, ?string $relatedKey = null)`
Defines the **inverse** of `morphToMany` (e.g., on `TagModel`, retrieve all `Post`s or all `Video`s that have this tag). Returns a `MorphToMany` instance with `$inverse = true`.

- **Example**:
  ```php
  // On TagModel
  public function posts()
  {
      return $this->morphedByMany(PostModel::class, 'taggable');
  }

  public function videos()
  {
      return $this->morphedByMany(VideoModel::class, 'taggable');
  }
  ```
- **Use case / Edge case**: Lets you call `$tagModel->with('posts', 'videos')->find($tagId)` or `$tag->posts()->count()` cleanly from the shared tag model.

---

### `hasRelation(string $name): bool` & `resolveRelation(string $name): Relation`
Introspection helpers on `RelationshipsTrait`.
- `hasRelation($name)` checks whether `$name` is registered in `$this->relations` or exists as a non-static, zero-required-argument relation method on the child model (safely ignoring built-in `CodeIgniter\Model` / `BaseModel` methods).
- `resolveRelation($name)` invokes or clones the relation and throws `InvalidArgumentException` if the relation is missing or doesn't return a `Relation` instance.
- **Use case**: Used internally by `EntityRelationshipsTrait` and useful if you build generic serializers, API filters, or admin panels that inspect model relationships dynamically.

---

## 2. Eager Loading & Lazy Eager Loading

### `with($relations, $clause = null): self`
Queues one or more relationships to be eager-loaded on the next `find()`, `findAll()`, `first()`, or `paginate()` call.

- **Accepted formats**:
  - Single name: `->with('posts')`
  - Multiple arguments: `->with('profile', 'posts', 'roles')`
  - Array of names: `->with(['profile', 'posts'])`
  - Single relation + constraint closure: `->with('posts', fn ($q) => $q->where('status', 'published'))`
  - Array mixing dot-notation, column selection, and closures:
    ```php
    $countries = model(CountryModel::class)->with([
        'users:id,country_id,name',
        'users.posts' => fn ($q) => $q->where('status', 'published'),
        'users.posts.comments.author:id,name',
    ])->findAll();
    ```
- **Use case / Edge case**: When using column selection (`'users:id,country_id,name'`), always include the foreign/local key columns needed to match children to parents (`country_id` on `users`, `id` if children below it need it). Multiple `with()` calls merge cleanly rather than overwriting each other.

---

### `withTree(array $tree): self`
Merges a pre-parsed eager-load tree directly into `$this->activeRelations`.
- **Use case**: Primarily used internally to forward nested dot-notation branches (like `'posts.comments.author'`) down to child models without re-parsing strings.

---

### `without($relations): self`
Excludes one or more relationships from the model's default `$with` array (or previously queued `with()`) for the next query only.
```php
// UserModel has: protected array $with = ['profile', 'roles'];
$users = model(UserModel::class)->without('profile')->findAll();
// or multiple: ->without(['profile', 'roles']) / ->without('profile', 'roles')
```
- **Use case / Edge case**: Useful when an endpoint needs a lightweight list without heavy default relations, while still keeping other defaults intact.

---

### `withoutRelations(): self`
Disables **all** default `$with` and `$withCount` properties as well as any queued `with()`/`withCount()` for the next query only.
```php
$rawUsers = model(UserModel::class)->withoutRelations()->findAll();
```
- **Use case / Edge case**: Essential in background jobs, exports, or login checks where a model defines default `$with` relations that you want to skip completely for maximum speed.

---

### `load(&$rows, $relations, $clause = null)`
Eager-loads relationships onto a single row/Entity or a list of rows/Entities that were **already fetched** from the database (without re-querying the parent rows).
```php
$users = $userModel->findAll();
$userModel->load($users, 'profile', 'posts');

// Also works on a single row or with a constraint closure:
$user = $userModel->find(1);
$userModel->load($user, 'posts', fn ($q) => $q->where('status', 'published'));
```
- **Use case / Edge case**: Useful when you only know which relations you need *after* inspecting the fetched records (e.g., loading extra details only if a condition in controller logic passes). Safely handles `null` or `[]` inputs as no-ops.

---

### `loadMissing(&$rows, $relations, $clause = null)`
Same as `load()`, except it **skips** any row where the relation is already loaded—preventing duplicate database queries and preserving already-hydrated/modified data.
```php
$userModel->loadMissing($users, 'profile', 'posts');
```
- **Use case / Edge case**: Works across partially loaded collections (e.g., row 0 already has `profile`, row 1 does not—it only queries for row 1) and recurses into nested relations (`'posts.comments'`) when the parent relation is already present.

---

## 3. Querying Relationship Existence

All existence methods add a correlated SQL `EXISTS` / `NOT EXISTS` / `COUNT(*)` subquery to the parent query, keeping parent rows deduplicated and pagination totals accurate.

### `has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'AND', ?callable $callback = null): self`
Filters parent records that have a certain number of related records (defaults to at least 1). Supports dot-notation (`'users.posts.comments'`).
```php
// At least 1 post
$users = model(UserModel::class)->has('posts')->findAll();
// At least 3 posts
$users = model(UserModel::class)->has('posts', '>=', 3)->findAll();
// Exactly 2 posts
$users = model(UserModel::class)->has('posts', '=', 2)->findAll();
```
- **Use case / Edge case**: Automatically uses fast `EXISTS (...)` for `>= 1` and `NOT EXISTS (...)` for `< 1`, falling back to `(SELECT COUNT(*) ...) $operator $count` for other counts. Valid operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=` (invalid operators throw `InvalidArgumentException`).

---

### `orHas(string $relation, string $operator = '>=', int $count = 1): self`
Same as `has()`, joined with SQL `OR` instead of `AND`.
```php
$users = model(UserModel::class)
    ->has('posts')
    ->orHas('videos')
    ->findAll();
```
- **Use case**: *"Users who have created either a post OR a video."*

---

### `doesntHave(string $relation, string $boolean = 'AND', ?callable $callback = null): self`
Filters parent records that have **zero** matching related records (shorthand for `has($relation, '<', 1)`).
```php
$inactiveUsers = model(UserModel::class)->doesntHave('posts')->findAll();
```
- **Use case / Edge case**: Finding orphaned or unused records (e.g., categories with no products, users with no orders). Soft-deleted child records do **not** count as existing, so a user whose only post was soft-deleted will properly match `doesntHave('posts')`.

---

### `orDoesntHave(string $relation): self`
Same as `doesntHave()`, joined with SQL `OR`.
```php
$users = model(UserModel::class)
    ->where('active', 0)
    ->orDoesntHave('profile')
    ->findAll();
```
- **Use case**: *"Users who are inactive OR haven't completed their profile."*

---

### `whereHas(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self`
Like `has()`, but lets you pass a closure to filter **which** related records count toward existence. Supports nested dot-notation (`'users.posts.comments'`).
```php
$users = model(UserModel::class)
    ->whereHas('posts', fn ($q) => $q->where('views >', 150))
    ->findAll();

// Nested dot notation: countries with at least 1 approved comment
$countries = model(CountryModel::class)
    ->whereHas('users.posts.comments', fn ($q) => $q->where('approved', 1))
    ->findAll();
```
- **Use case / Edge case**: Handles self-referencing models (`CategoryModel::whereHas('children', fn ($q) => $q->where('active', 1))`) by automatically aliasing the inner table (`__rel_self`) so SQL doesn't complain about ambiguous table names.

---

### `orWhereHas(string $relation, ?callable $callback = null, string $operator = '>=', int $count = 1): self`
Same as `whereHas()`, joined with SQL `OR`.
```php
$users = model(UserModel::class)
    ->where('id', 3)
    ->orWhereHas('posts', fn ($q) => $q->where('views', 200))
    ->findAll();
```
- **Use case**: Combining direct column filters with alternative relationship conditions.

---

### `whereDoesntHave(string $relation, ?callable $callback = null): self`
Filters parent records that have **no** related records matching the given constraint closure.
```php
// Users who have NO published posts (includes users with 0 posts OR only draft posts)
$users = model(UserModel::class)
    ->whereDoesntHave('posts', fn ($q) => $q->where('status', 'published'))
    ->findAll();
```
- **Use case / Edge case**: Crucial difference from `whereHas(..., 'status !=', 'published')`—a user with *zero* posts wouldn't match a positive `whereHas`, whereas `whereDoesntHave` correctly includes both users with 0 posts and users with only drafts.

---

### `orWhereDoesntHave(string $relation, ?callable $callback = null): self`
Same as `whereDoesntHave()`, joined with SQL `OR`.
```php
$users = model(UserModel::class)
    ->where('active', 0)
    ->orWhereDoesntHave('posts', fn ($q) => $q->where('status', 'published'))
    ->findAll();
```
- **Use case**: Auditing accounts that are disabled OR have zero published content.

---

## 4. Relationship Aggregates (Counts, Sums, Averages, Exists)

### Query-Time Aggregates (Executed as SQL Subqueries)
All `with*` aggregate methods run as subqueries in the parent `SELECT` list, so you can `orderBy()` the resulting alias. They work alongside `select()` regardless of whether you call `select()` before or after `withCount()`.

- **`withCount($relations): self`**  
  Adds `{relation}_count` (cast to `int`, `0` when none). Supports custom aliases (`'posts as published_count'`) and constraint closures:
  ```php
  $users = model(UserModel::class)
      ->withCount([
          'posts',
          'posts as published_posts_count' => fn ($q) => $q->where('status', 'published'),
      ])
      ->orderBy('posts_count', 'DESC')
      ->findAll();
  ```
- **`withSum($relations, string $column): self`**  
  Adds `{relation}_sum_{column}` (cast to `int` or `float`, or `null` if no rows).
  ```php
  $users = model(UserModel::class)->withSum('posts', 'views')->findAll();
  // $users[0]['posts_sum_views']
  ```
- **`withAvg($relations, string $column): self`**  
  Adds `{relation}_avg_{column}` (cast to `float`, or `null` if no rows).
  ```php
  $users = model(UserModel::class)->withAvg('posts', 'views')->findAll();
  ```
- **`withMin($relations, string $column): self`**  
  Adds `{relation}_min_{column}` (or `null` if no rows).
  ```php
  $users = model(UserModel::class)->withMin('posts', 'views')->findAll();
  ```
- **`withMax($relations, string $column): self`**  
  Adds `{relation}_max_{column}` (or `null` if no rows).
  ```php
  $users = model(UserModel::class)->withMax('posts', 'views')->findAll();
  ```
- **`withExists($relations): self`**  
  Adds `{relation}_exists` (cast to `bool`: `true` or `false`). Faster than `withCount()` when you only need a yes/no badge.
  ```php
  $users = model(UserModel::class)->withExists('profile')->findAll();
  // $users[0]['profile_exists'] === true
  ```
- **`withAggregate($relations, string $column, ?string $function = null): self`**  
  Low-level method powering all the methods above—useful if you want a custom SQL aggregate function (e.g., `GROUP_CONCAT` or custom expression).

- **Use case / Edge case for all `with*` aggregates**:
  - You can define `protected array $withCount = ['posts'];` on a Model to attach counts by default (and suppress them with `withoutRelations()`).
  - On Entities, aggregate fields are synced with `$original` so `$entity->hasChanged()` stays `false` and `$model->save($entity)` stays safe.
  - All `with*` aggregates respect soft deletes on the related model.

---

### Lazy Aggregates (For Already-Fetched Rows or Entities)
Each `with*` method has a matching `load*` method that executes a single `GROUP BY` batch query against already-fetched rows/Entities:

- **`loadCount(&$rows, $relations)`** — Adds `{relation}_count` (`int`).
- **`loadSum(&$rows, $relations, string $column)`** — Adds `{relation}_sum_{column}`.
- **`loadAvg(&$rows, $relations, string $column)`** — Adds `{relation}_avg_{column}`.
- **`loadMin(&$rows, $relations, string $column)`** — Adds `{relation}_min_{column}`.
- **`loadMax(&$rows, $relations, string $column)`** — Adds `{relation}_max_{column}`.
- **`loadExists(&$rows, $relations)`** — Adds `{relation}_exists` (`bool`).
- **`loadAggregate(&$rows, $relations, string $column, ?string $function = null)`** — Underlying batch aggregate loader.

```php
$users = $userModel->findAll();
$userModel->loadCount($users, ['posts', 'roles']);
$userModel->loadSum($users, 'posts', 'views');
$userModel->loadExists($users, 'profile');
```
- **Use case / Edge case**: Great when you forgot to call `withCount()` during the initial query, or when loading counts conditionally after fetching a paginated result. Works identically on single rows, arrays of rows, or Entities (`$user->loadCount('posts')`).

---

## 5. Relation Modifiers (Defaults, One-of-Many, Pivot & Morph Options)

These methods are chained directly onto the relation object returned inside your model's relationship method (or inside a `with(['rel' => fn ($rel) => ...])` closure).

### `withDefault($callback = true): self` *(HasOne, BelongsTo, MorphOne)*
Returns a fallback array, `stdClass`, Entity, or closure result instead of `null` when no related record exists.
```php
public function profile()
{
    return $this->hasOne(ProfileModel::class)->withDefault([
        'bio' => 'No bio yet',
    ]);
}

// Or with a callback receiving ($defaultInstance, $parentRow):
public function author()
{
    return $this->belongsTo(UserModel::class, 'user_id')->withDefault(function ($user, $post) {
        $user['name'] = 'Guest Author';
        return $user;
    });
}
```
- **Use case / Edge case**: Eliminates `Null Object` checks in views (`$post['author']['name']` never throws an undefined offset/property error on orphaned records). Matches the related model's `$returnType` (`array`, `object`, or `Entity` class) automatically.

---

### `ofMany($column = null, string $aggregate = 'max', ?callable $relation = null): self` *(HasOne, MorphOne)*
Converts a one-to-many table into a **one-of-many** single record per parent (picking the row with the `max` or `min` value on one or more columns, with primary key tie-breaking).
```php
public function topPost()
{
    return $this->hasOne(PostModel::class)->ofMany('views', 'max');
}

// Multi-column tie-breaker:
public function currentPrice()
{
    return $this->hasOne(PriceModel::class)->ofMany([
        'effective_at' => 'max',
        'id'           => 'max',
    ]);
}
```
- **Use case / Edge case**: Fetches only the winning row per parent instead of loading thousands of historical rows into PHP. Also works inside `whereHas('topPost', ...)` via an automatic `NOT EXISTS` tie-breaker subquery.

---

### `latestOfMany($column = null): self` & `oldestOfMany($column = null): self` *(HasOne, MorphOne)*
Convenient shortcuts for `ofMany($column, 'max')` and `ofMany($column, 'min')`. `$column` defaults to the related model's primary key (`'id'`). Accepts a single column name or an array of columns.
```php
public function latestPost()
{
    return $this->hasOne(PostModel::class)->latestOfMany(); // max(id)
}

public function oldestLogin()
{
    return $this->hasOne(LoginModel::class)->oldestOfMany('logged_in_at');
}
```
- **Use case**: Getting a user's most recent order, latest status log, or first subscription without extra subqueries in your controllers.

---

### `withPivot($columns): self` *(BelongsToMany, MorphToMany)*
Specifies additional columns from the intermediate pivot table to include in hydrated `pivot` data (accepts an array or variadic strings).
```php
return $this->belongsToMany(RoleModel::class)->withPivot('assigned_by', 'role_label');
```
- **Use case**: Essential whenever your pivot table stores metadata beyond the two foreign keys (e.g., `quantity` and `unit_price` on `order_product`).

---

### `withTimestamps(string $createdAt = 'created_at', string $updatedAt = 'updated_at'): self` *(BelongsToMany, MorphToMany)*
Automatically includes `created_at` and `updated_at` in `pivot` reads and automatically populates them during `attach()`, `sync()`, and `updateExistingPivot()`.
```php
return $this->belongsToMany(RoleModel::class)->withTimestamps();
```
- **Use case / Edge case**: Automatically formats timestamps according to your parent model's `$dateFormat` (`'datetime'`, `'date'`, or `'int'`).

---

### `as(string $accessor): self` *(BelongsToMany, MorphToMany)*
Renames the `pivot` key/property on hydrated related items to something more descriptive.
```php
return $this->belongsToMany(PodcastModel::class)->as('subscription')->withPivot('expires_at');
// Access via $podcast['subscription']['expires_at'] or $podcast->subscription->expires_at
```
- **Use case**: Makes domain code read naturally (`$user->podcasts[0]->subscription->expires_at` instead of `->pivot->expires_at`).

---

### `using(string $pivotClass): self` *(BelongsToMany, MorphToMany)*
Uses a custom `Pivot` Entity subclass when hydrating object/Entity results instead of the default `IsaacMachakata\Ci4Relationships\Relations\Pivot`.
```php
return $this->belongsToMany(RoleModel::class)->using(UserRolePivot::class);
```
- **Use case**: Useful when you want custom casts (`$casts`), getters, or helper methods on the pivot record itself.

---

### Pivot Filtering & Ordering *(BelongsToMany, MorphToMany)*
Filter or sort many-to-many results by columns on the intermediate pivot table:
- **`wherePivot(string $column, $operator = null, $value = null, string $boolean = 'AND'): self`**
- **`orWherePivot(string $column, $operator = null, $value = null): self`**
- **`wherePivotIn(string $column, array $values): self`**
- **`wherePivotNotIn(string $column, array $values): self`**
- **`wherePivotNull(string $column): self`**
- **`wherePivotNotNull(string $column): self`**
- **`orderByPivot(string $column, string $direction = 'asc'): self`**

```php
$users = model(UserModel::class)->with([
    'roles' => fn ($q) => $q->wherePivot('role_label', 'Writer')->orderByPivot('created_at', 'desc'),
])->findAll();
```
- **Use case / Edge case**: Automatically qualifies pivot column names with the pivot table name so columns like `created_at` or `status` never collide with columns of the same name on the related table.

---

### `withDeletedParents(bool $val = true): self` *(HasOneThrough, HasManyThrough)*
Includes soft-deleted intermediate (`$through`) records when traversing a through relationship.
```php
public function allPostsIncludingDeletedUsers()
{
    return $this->hasManyThrough(PostModel::class, UserModel::class)->withDeletedParents();
}
```
- **Use case**: Compliance/audit reports where you still want to see orders belonging to archived/soft-deleted customers.

---

### `morphWith(array $with): self` *(MorphTo)*
Specifies different nested relationships to eager-load depending on which model type each polymorphic row resolves to.
```php
$comments = model(CommentModel::class)->with([
    'commentable' => fn ($morphTo) => $morphTo->morphWith([
        PostModel::class  => ['author'],
        VideoModel::class => ['user'],
    ]),
])->findAll();
```
- **Use case / Edge case**: Solves the classic polymorphic problem where `Post` has `author()` while `Video` has `user()`, letting you load each type's specific relations in batches without throwing an "undefined relation" error.

---

### `for($parentRow): self` *(All Relations)*
Binds a specific parent row (array, `stdClass`, or `Entity`) to a `Relation` instance so you can run direct queries or writes when your model returns arrays instead of Entities using `EntityRelationshipsTrait`.
```php
$userModel = model(UserModel::class);
$user      = $userModel->find(1);

$published = $userModel->posts()->for($user)->where('status', 'published')->findAll();
$userModel->posts()->for($user)->create(['title' => 'New Post']);
```
- **Use case**: Gives array-based and `stdClass`-based models 100% of the same direct query/write capabilities as Entity models.

---

## 6. Querying & Writing Directly Through a Relation

When a relation is bound to a parent—either automatically via `$entity->posts()` (`EntityRelationshipsTrait`) or via `->for($parent)`—you can chain any CodeIgniter 4 Query Builder / Model method (`where`, `orWhere`, `like`, `orderBy`, `limit`, `withDeleted`, etc.) and execute the methods below.

### Direct Query Execution Methods (`Relation`)

- **`toQuery(): Model`**  
  Builds and returns the configured related `Model` instance with parent constraints and pending calls applied (without executing it yet).  
  *Use case / Edge case:* Useful for inspecting the compiled SQL (`$user->posts()->toQuery()->builder()->getCompiledSelect()`) or passing the configured query to custom helpers. Throws `LogicException` if no parent row is bound.
- **`findAll(?int $limit = null, int $offset = 0): array`**  
  Executes the query and returns all matching related rows (hydrating `pivot` on `BelongsToMany` / `MorphToMany` and stripping internal `__through_parent_key` on `Through` relations).
- **`get(?int $limit = null, int $offset = 0): array`**  
  Laravel-style alias for `findAll()`.
- **`first()`**  
  Returns the first matching related row, or `null` (or default model if `withDefault()` is configured on `HasOne` / `BelongsTo` / `MorphOne`).
- **`find($id = null)`**  
  Finds a related record (or array of IDs) by primary key, **scoped to the parent**—meaning `$user->posts()->find(99)` returns `null` if post 99 belongs to a different user!  
  *Use case / Edge case:* Prevents IDOR (Insecure Direct Object Reference) bugs when editing/viewing a user's child record by ID.
- **`findColumn(string $columnName): ?array`**  
  Returns a flat array of values for a single column scoped to the parent (e.g., `$user->posts()->findColumn('title')`).
- **`paginate(?int $perPage = null, string $group = 'default', ?int $page = null, int $segment = 0): ?array`**  
  Paginates related records scoped to the parent, setting `$pager` on the underlying query model.
- **`count(bool $reset = true): int` & `countAllResults(bool $reset = true, bool $test = false): int`**  
  Counts matching related records scoped to the parent without loading rows into PHP.
- **`exists(): bool` & `doesntExist(): bool`**  
  Returns `true`/`false` depending on whether at least 1 matching related record exists for the parent.
- **`sum(string $column)`, `avg(string $column)`, `min(string $column)`, `max(string $column)`**  
  Runs SQL `SUM`, `AVG`, `MIN`, or `MAX` on `$column` scoped to the parent (respecting soft deletes). Returns `null` if no rows match (`avg()` returns `?float`, `sum()` returns `int|float|null`).
- **`chunk(int $size, Closure $callback): void`**  
  Iterates over large sets of related records in batches of `$size` to keep memory usage flat.
- **`update($data): bool`**  
  Mass-updates all related rows matching the relation (and any chained `where()` calls) scoped to the parent. Accepts an array or object.
- **`delete($id = null, bool $purge = false): bool`**  
  Deletes related records scoped to the parent (soft-deleting automatically if the related model uses `$useSoftDeletes = true`, unless `$purge = true`).

---

### `HasOne`, `HasMany`, `MorphOne`, `MorphMany` Write Methods

- **`save($record): bool`**  
  Automatically sets the foreign key (and `morphType` on morph relations) to the parent's key and saves `$record` (insert or update) via the related model.
  ```php
  $post = ['title' => 'Saved via relation', 'status' => 'published'];
  $user->posts()->save($post);
  ```
- **`saveMany(array $records): bool`**  
  Calls `save()` for each record in `$records`, returning `false` immediately if any save fails.
- **`create(array $attributes = [])`**  
  Merges the parent's foreign key (and `morphType`) into `$attributes`, inserts using the related model (instantiating its Entity if `$returnType` is an Entity class), and returns the newly fetched row/Entity (or `false` on validation/insert failure).
  ```php
  $post = $user->posts()->create(['title' => 'Hello World', 'status' => 'published']);
  ```
- **`createMany(array $records): array`**  
  Creates multiple related records in sequence and returns an array of the created rows/Entities.

---

### `BelongsTo` & `MorphTo` Write Methods

- **`associate($model)`** *(BelongsTo)* / **`associate($model, ?string $morphModelClass = null)`** *(MorphTo)*  
  Sets the foreign key (and `morphType` for `MorphTo`) on the bound child row/Entity and caches the relation if it uses `EntityRelationshipsTrait`. Does **not** hit the DB until you `$model->save($child)`—matching Laravel's behavior so you can set multiple fields before one `UPDATE`.
  ```php
  // BelongsTo (accepts an Entity, array, stdClass, or raw ID):
  $postModel->author()->for($post)->associate($user);
  $postModel->save($post);

  // MorphTo (pass target Model class unless $target uses EntityRelationshipsTrait):
  $commentModel->commentable()->for($comment)->associate($post, PostModel::class);
  $commentModel->save($comment);
  ```
- **`dissociate()`** *(BelongsTo, MorphTo)*  
  Sets the foreign key (and `morphType` on `MorphTo`) to `null` on the bound child row/Entity.
  ```php
  $postModel->author()->for($post)->dissociate();
  $postModel->save($post);
  ```

---

### `BelongsToMany` & `MorphToMany` Pivot Mutation Methods

- **`attach($ids, array $attributes = []): bool`**  
  Inserts one or more pivot rows for the parent. Accepts a single ID, an Entity/object, an array of IDs, or `[id => ['pivot_col' => 'val']]`. Automatically adds `morphType` on `MorphToMany` and timestamps if `withTimestamps()` is enabled.
  ```php
  $user->roles()->attach(1);
  $user->roles()->attach([1, 2 => ['role_label' => 'Lead']], ['assigned_by' => 99]);
  ```
- **`detach($ids = null): int`**  
  Removes specific related IDs (or **all** pivot records for this parent if `$ids === null`) and returns the number of affected rows. On `MorphToMany`, safely restricts deletion to `$this->morphType = $this->morphClass`.
- **`sync(array $ids, bool $detaching = true): array`**  
  Synchronizes the pivot table so only the given IDs remain attached. Supports `[1, 2 => ['role_label' => 'Editor']]`. Returns `['attached' => [...], 'detached' => [...], 'updated' => [...]]`.
  *Use case / Edge case:* Ideal for multi-select checkboxes in forms—you pass the submitted role IDs and `sync()` computes the exact inserts, deletes, and pivot attribute updates automatically.
- **`syncWithoutDetaching(array $ids): array`**  
  Shortcut for `sync($ids, false)`—attaches new IDs and updates existing pivot attributes without removing anything already attached.
- **`toggle($ids): array`**  
  Attaches IDs that aren't currently attached and detaches IDs that already are. Returns `['attached' => [...], 'detached' => [...]]`.
  *Use case:* Like/bookmark/favorite toggles or feature-flag opt-ins.
- **`updateExistingPivot($id, array $attributes): bool`**  
  Updates extra pivot columns on an existing pivot link for `$id` (automatically refreshing `updated_at` when `withTimestamps()` is enabled).

---

## 7. Polymorphic Morph Maps

Static methods on `IsaacMachakata\Ci4Relationships\Relations\Relation`:

- **`Relation::morphMap(?array $map = null, bool $merge = true): array`**  
  Registers (or retrieves) a mapping between short string aliases and Model FQCNs.
  ```php
  Relation::morphMap([
      'post'  => PostModel::class,
      'video' => VideoModel::class,
  ]);
  ```
- **`Relation::enforceMorphMap(array $map, bool $merge = true): array`**  
  Registers the morph map **and** enforces that every polymorphic model has an alias (throwing `InvalidArgumentException` if an unmapped model is used).
  *Use case / Edge case:* Put this in `Config\Boot\production.php` or `BaseController` so refactoring namespaces never breaks existing database rows, and unmapped models fail loudly during development/tests.
- **`Relation::requiresMorphMap(?bool $value = null): bool`**  
  Gets or sets whether a morph map is required.
- **`Relation::getMorphedModel(string $alias): ?string`**  
  Returns the Model FQCN for an alias, or `null` if not mapped.
- **`Relation::getMorphAlias(string $class): string`**  
  Returns the alias for a Model FQCN (or the FQCN itself if not enforced, or throws if `enforceMorphMap` is active).

---

## 8. Entity Support (`EntityRelationshipsTrait`)

Add `use EntityRelationshipsTrait;` to any `CodeIgniter\Entity\Entity` subclass to unlock Laravel-style object access:

```php
<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;
use IsaacMachakata\Ci4Relationships\EntityRelationshipsTrait;

class User extends Entity
{
    use EntityRelationshipsTrait;
}
```

### Magic Property & Method Access
- **`$entity->relationName` (`__get`, `__set`, `__isset`, `__unset`)**  
  If the relation is already loaded in `$_relations`, returns it immediately. Otherwise, lazily executes the query once, caches the result in `$_relations`, and returns it. Setting or unsetting a relation stores/removes it in `$_relations` without touching `$attributes`.  
  *Edge case:* Real columns, `datamap` aliases, and custom `get*()` getters always take priority over relation lookup.
- **`$entity->relationName()` (`__call`)**  
  Returns the parent-bound `Relation` instance (`->for($this)`) so you can chain query builder methods (`$user->posts()->where('status', 'published')->findAll()`) or writes (`$user->posts()->create(...)`, `$user->roles()->sync([1, 2])`).

### Entity Methods
- **`load($relations): self`** — Lazy-loads one or more relations onto this entity (`$user->load('profile', 'posts')`).
- **`loadMissing($relations): self`** — Loads relations onto this entity only if not already loaded.
- **`loadCount($relations): self`** — Lazy-loads `{relation}_count` onto this entity.
- **`relationLoaded(string $name): bool`** — Returns `true` if `$name` has already been loaded on this entity (even if its value is `null` or `[]`).
- **`getRelation(string $name)`** — Returns the loaded relation value without triggering lazy loading (`null` if unloaded).
- **`getRelations(): array`** — Returns the entire `$_relations` array.
- **`setRelation(string $name, $value): self`** — Manually assigns a relation value in `$_relations` without dirtying `hasChanged()`.
- **`setRelations(array $relations): self`** — Replaces all loaded relations at once.
- **`unsetRelation(string $name): self`** — Clears a cached relation (the next property access will re-query if needed).
- **`setModelClass(string $modelClass, $connection = null): self` & `getModelClass(): ?string`**  
  Automatically called by `RelationshipsTrait` whenever a model loads the entity so the entity knows which model defines its relations. If you instantiate an entity manually (`new User(['id' => 1])`) without querying through a model first, you can set `protected string $modelClass = UserModel::class;` on the entity or call `$user->setModelClass(UserModel::class)`.
- **`toArray(bool $onlyChanged = false, bool $cast = true, bool $recursive = false): array`**  
  When `$onlyChanged === false`, merges loaded relations recursively into the output array (so `json_encode($user)` includes loaded relations automatically). When `$onlyChanged === true`, excludes relations.
- **`toRawArray(bool $onlyChanged = false, bool $recursive = false): array`** *(inherited from CI4 `Entity`)*  
  Reads `$attributes` only, meaning `Model::save($entity)` never accidentally includes loaded relations in `INSERT` / `UPDATE` queries.

---

## 9. Inspection & Utility Methods

### On `Relation` Instances
Useful when inspecting relation metadata or building custom tooling:
- `getParent(): Model` — Returns the parent model instance.
- `getRelatedClass(): string` — Returns the related model class name.
- `getRelated(): Model` — Returns (and lazily creates) the related model instance.
- `newRelatedInstance(): Model` — Creates a fresh non-shared related model instance.
- `getRelationName(): string` / `setRelationName(string $name): self` — Gets or sets the relation's property name.
- `setSelectedColumns(?array $columns): self` — Sets columns to select during eager loading.
- **Key getters**:
  - `HasOneOrMany`: `getForeignKeyName()`, `getLocalKeyName()`
  - `BelongsTo`: `getForeignKeyName()`, `getOwnerKeyName()`
  - `BelongsToMany`: `getTable()`, `getForeignPivotKeyName()`, `getRelatedPivotKeyName()`, `getParentKeyName()`, `getRelatedKeyName()`
  - `MorphOneOrMany`: `getMorphType()`, `getMorphClass()` (plus `getForeignKeyName()`, `getLocalKeyName()`)
  - `MorphTo`: `getMorphType()`, `getForeignKeyName()`
  - `MorphToMany`: `getMorphType()`, `getMorphClass()` (plus all `BelongsToMany` getters)
- **`Pivot` Entity (`IsaacMachakata\Ci4Relationships\Relations\Pivot`)**:
  - `getTable(): ?string` — Returns the pivot table name.
  - Implements `ArrayAccess` (`offsetExists`, `offsetGet`, `offsetSet`, `offsetUnset`) so pivot data works identically as `$item->pivot->col` or `$item->pivot['col']`.

---

## Testing

The package includes a comprehensive PHPUnit test suite backed by an in-memory SQLite database covering all relation types, nested eager loading, `N+1` query count assertions, soft deletes, UUID keys, aggregates, existence queries, and Entity operations:

```bash
composer test
```

## Credits

Originally forked from [michalsn/codeigniter-nested-model](https://github.com/michalsn/codeigniter-nested-model) then later heavily updated with Gemini Hydrogen for v2.