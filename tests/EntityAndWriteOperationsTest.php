<?php

namespace Tests;

use Tests\Fixtures\PlainUserEntity;
use Tests\Fixtures\PostModel;
use Tests\Fixtures\UserEntityModel;
use Tests\Fixtures\UserModel;

class EntityAndWriteOperationsTest extends DatabaseTestCase
{
    public function testEntityLazyLoadingAndCaching(): void
    {
        $user = (new UserEntityModel())->find(1);

        $this->assertFalse($user->relationLoaded('profile'));
        $this->assertFalse($user->relationLoaded('posts'));

        // Accessing $user->profile lazy-loads and caches it
        $this->assertSame('Alice bio', $user->profile['bio']);
        $this->assertTrue($user->relationLoaded('profile'));

        // Accessing $user->posts lazy-loads and caches it
        $this->assertCount(2, $user->posts);
        $this->assertTrue($user->relationLoaded('posts'));

        // Save after loading relations must NOT fail or dirty Entity
        $this->assertFalse($user->hasChanged());
        $user->name = 'Alice Updated';
        $this->assertTrue((new UserEntityModel())->save($user));

        $reloaded = (new UserEntityModel())->find(1);
        $this->assertSame('Alice Updated', $reloaded->name);
    }

    public function testEntityToArrayAndJsonEncodeIncludesLoadedRelations(): void
    {
        $user = (new UserEntityModel())->with('profile', 'posts')->find(1);

        $arr = $user->toArray();
        $this->assertArrayHasKey('profile', $arr);
        $this->assertArrayHasKey('posts', $arr);
        $this->assertSame('Alice bio', $arr['profile']['bio']);

        $decoded = json_decode(json_encode($user), true);
        $this->assertSame('Alice bio', $decoded['profile']['bio']);
        $this->assertCount(2, $decoded['posts']);

        // toRawArray must exclude relations so CI4 Model::save() stays safe
        $raw = $user->toRawArray();
        $this->assertArrayNotHasKey('profile', $raw);
        $this->assertArrayNotHasKey('posts', $raw);
    }

    public function testEntityRelationMethodChainingAndWrites(): void
    {
        $user = (new UserEntityModel())->find(3); // Charlie has 0 posts & 0 roles

        // Direct query on Entity relation method
        $this->assertSame(0, $user->posts()->count());
        $this->assertTrue($user->posts()->doesntExist());

        // Create related post through Entity relation
        $created = $user->posts()->create([
            'title'  => 'Charlie First Post',
            'status' => 'published',
            'views'  => 42,
        ]);
        $this->assertIsArray($created);
        $this->assertSame(3, (int) $created['user_id']);
        $this->assertSame(1, $user->posts()->count());
        $this->assertSame(42, $user->posts()->sum('views'));

        // Update & delete through relation
        $user->posts()->where('status', 'published')->update(['views' => 77]);
        $this->assertSame(77, $user->posts()->sum('views'));

        // Pivot operations directly on Entity
        $user->roles()->attach([1 => ['role_label' => 'Trial']]);
        $this->assertSame(1, $user->roles()->count());

        $user->load('roles', 'posts');
        $this->assertCount(1, $user->roles);
        $this->assertSame('Trial', $user->roles[0]['pivot']['role_label']);
    }

    public function testEntityLoadAndLoadMissing(): void
    {
        $user = (new UserEntityModel())->find(1);
        $user->loadMissing('profile');
        $this->assertTrue($user->relationLoaded('profile'));
        $this->assertFalse($user->relationLoaded('posts'));

        $user->load('posts');
        $this->assertTrue($user->relationLoaded('posts'));
    }

    public function testPlainCodeIgniterEntityWithoutTraitRemainsSaveSafe(): void
    {
        $model = new class ($this->db) extends UserModel {
            protected $returnType = PlainUserEntity::class;
        };

        $user = $model->with('profile', 'posts')->find(1);
        $this->assertInstanceOf(PlainUserEntity::class, $user);
        $this->assertSame('Alice bio', $user->profile['bio']);
        $this->assertCount(2, $user->posts);

        // Plain Entity hasChanged() must NOT be dirtied by eager-loaded relations!
        $this->assertFalse($user->hasChanged('profile'));
        $this->assertFalse($user->hasChanged('posts'));
        $this->assertFalse($user->hasChanged());

        // Updating another field and saving with protectFields = false must succeed!
        $user->name = 'Alice Plain';
        $this->assertTrue($model->protect(false)->save($user));
        $this->assertSame('Alice Plain', $model->find(1)->name);
    }

    public function testHasManyCreateManySaveAndSaveMany(): void
    {
        $userModel = new UserModel();
        $charlie   = $userModel->find(3);

        $created = $userModel->posts()->for($charlie)->createMany([
            ['title' => 'C1', 'status' => 'published', 'views' => 10],
            ['title' => 'C2', 'status' => 'draft', 'views' => 20],
        ]);
        $this->assertCount(2, $created);
        $this->assertSame(2, $userModel->posts()->for($charlie)->count());

        $posts = (new PostModel())->where('user_id', 3)->findAll();
        $this->assertCount(2, $posts);
    }
}
