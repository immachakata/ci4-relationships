<?php

namespace Tests;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Config as DbConfig;
use IsaacMachakata\Ci4Relationships\Relations\Relation;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

abstract class DatabaseTestCase extends TestCase
{
    protected BaseConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap([], false);
        Relation::requiresMorphMap(false);

        $this->db = DbConfig::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBDebug'  => true,
        ], false);

        $this->db->initialize();

        // Point the 'tests' connection group to this fresh in-memory SQLite instance
        // so both `new SomeModel($this->db)` and `new SomeModel()` share it.
        $prop = new ReflectionProperty(DbConfig::class, 'instances');
        $prop->setAccessible(true);
        $instances          = $prop->getValue();
        $instances['tests'] = $this->db;
        $prop->setValue(null, $instances);

        $this->createSchema();
        $this->seedBaseData();
    }

    protected function createSchema(): void
    {
        $statements = [
            'CREATE TABLE countries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )',
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                country_id INTEGER NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                deleted_at TEXT NULL
            )',
            'CREATE TABLE profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                bio TEXT NULL,
                avatar_url TEXT NULL
            )',
            'CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "published",
                views INTEGER NOT NULL DEFAULT 0,
                published_at TEXT NULL,
                deleted_at TEXT NULL
            )',
            'CREATE TABLE videos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                title TEXT NOT NULL
            )',
            'CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                commentable_id INTEGER NOT NULL,
                commentable_type TEXT NOT NULL,
                user_id INTEGER NULL,
                body TEXT NOT NULL,
                approved INTEGER NOT NULL DEFAULT 1,
                deleted_at TEXT NULL
            )',
            'CREATE TABLE images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                imageable_id INTEGER NOT NULL,
                imageable_type TEXT NOT NULL,
                url TEXT NOT NULL,
                is_primary INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                deleted_at TEXT NULL
            )',
            'CREATE TABLE role_user (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                assigned_by INTEGER NULL,
                role_label TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )',
            'CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )',
            'CREATE TABLE taggables (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tag_id INTEGER NOT NULL,
                taggable_id INTEGER NOT NULL,
                taggable_type TEXT NOT NULL
            )',
            'CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER NULL,
                name TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1
            )',
            'CREATE TABLE tenants (
                uuid TEXT PRIMARY KEY,
                name TEXT NOT NULL
            )',
            'CREATE TABLE tenant_items (
                uuid TEXT PRIMARY KEY,
                tenant_uuid TEXT NOT NULL,
                label TEXT NOT NULL
            )',
        ];

        foreach ($statements as $sql) {
            $this->db->query($sql);
        }
    }

    protected function seedBaseData(): void
    {
        $this->db->table('countries')->insertBatch([
            ['id' => 1, 'name' => 'Zimbabwe'],
            ['id' => 2, 'name' => 'Kenya'],
            ['id' => 3, 'name' => 'EmptyLand'],
        ]);

        $this->db->table('users')->insertBatch([
            ['id' => 1, 'country_id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1],
            ['id' => 2, 'country_id' => 1, 'name' => 'Bob', 'email' => 'bob@example.com', 'active' => 1],
            ['id' => 3, 'country_id' => 2, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'active' => 0],
        ]);

        $this->db->table('profiles')->insertBatch([
            ['id' => 1, 'user_id' => 1, 'bio' => 'Alice bio', 'avatar_url' => 'alice.png'],
            ['id' => 2, 'user_id' => 2, 'bio' => 'Bob bio', 'avatar_url' => 'bob.png'],
            // User 3 intentionally has no profile
        ]);

        $this->db->table('posts')->insertBatch([
            ['id' => 1, 'user_id' => 1, 'title' => 'Alice Post 1', 'status' => 'published', 'views' => 100, 'published_at' => '2026-01-01', 'deleted_at' => null],
            ['id' => 2, 'user_id' => 1, 'title' => 'Alice Post 2', 'status' => 'draft', 'views' => 50, 'published_at' => '2026-01-05', 'deleted_at' => null],
            ['id' => 3, 'user_id' => 1, 'title' => 'Alice Deleted Post', 'status' => 'published', 'views' => 999, 'published_at' => '2026-01-10', 'deleted_at' => '2026-01-11 00:00:00'],
            ['id' => 4, 'user_id' => 2, 'title' => 'Bob Post 1', 'status' => 'published', 'views' => 200, 'published_at' => '2026-01-03', 'deleted_at' => null],
            ['id' => 5, 'user_id' => null, 'title' => 'Orphan Post', 'status' => 'published', 'views' => 10, 'published_at' => '2026-01-04', 'deleted_at' => null],
        ]);

        $this->db->table('videos')->insertBatch([
            ['id' => 1, 'user_id' => 1, 'title' => 'Intro Video'],
            ['id' => 2, 'user_id' => 2, 'title' => 'Demo Video'],
        ]);

        $this->db->table('roles')->insertBatch([
            ['id' => 1, 'name' => 'Admin'],
            ['id' => 2, 'name' => 'Editor'],
            ['id' => 3, 'name' => 'Viewer'],
        ]);

        $this->db->table('role_user')->insertBatch([
            ['user_id' => 1, 'role_id' => 1, 'assigned_by' => 99, 'role_label' => 'Super', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
            ['user_id' => 1, 'role_id' => 2, 'assigned_by' => 99, 'role_label' => 'Writer', 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ['user_id' => 2, 'role_id' => 3, 'assigned_by' => 1, 'role_label' => 'Reader', 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-03 00:00:00'],
        ]);

        $this->db->table('categories')->insertBatch([
            ['id' => 1, 'parent_id' => null, 'name' => 'Electronics', 'active' => 1],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Phones', 'active' => 1],
            ['id' => 3, 'parent_id' => 1, 'name' => 'Laptops', 'active' => 0],
            ['id' => 4, 'parent_id' => 2, 'name' => 'Smartphones', 'active' => 1],
        ]);
    }
}
