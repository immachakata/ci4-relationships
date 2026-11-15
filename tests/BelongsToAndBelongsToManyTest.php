<?php

namespace Tests;

use Tests\Fixtures\CategoryModel;
use Tests\Fixtures\PostModel;
use Tests\Fixtures\RoleModel;
use Tests\Fixtures\UserEntityModel;
use Tests\Fixtures\UserModel;

class BelongsToAndBelongsToManyTest extends DatabaseTestCase
{
    public function testBelongsToWithNullForeignKeyAndDefault(): void
    {
        $posts = (new PostModel())->with('author', 'authorWithDefault')->orderBy('id', 'ASC')->findAll();

        // Post 1 -> Alice, Post 2 -> Alice, Post 4 -> Bob, Post 5 -> null user_id (soft deleted id=3 excluded)
        $this->assertCount(4, $posts);
        $this->assertSame('Alice', $posts[0]['author']['name']);
        $this->assertSame('Bob', $posts[2]['author']['name']);
        $this->assertNull($posts[3]['author']);
        $this->assertSame('Guest', $posts[3]['authorWithDefault']['name']);
    }

    public function testBelongsToAssociateAndDissociate(): void
    {
        $postModel = new PostModel();
        $user      = (new UserModel())->find(2);
        $post      = $postModel->find(5);

        $updated = $postModel->author()->for($post)->associate($user);
        $this->assertSame(2, (int) $updated['user_id']);

        $postModel->save($updated);
        $reloaded = $postModel->with('author')->find(5);
        $this->assertSame('Bob', $reloaded['author']['name']);

        $dissociated = $postModel->author()->for($reloaded)->dissociate();
        $this->assertNull($dissociated['user_id']);
    }

    public function testBelongsToManyWithPivotAndTimestamps(): void
    {
        $users = (new UserModel())->with('roles')->orderBy('id', 'ASC')->findAll();

        // User 1 has roles 1 (Admin) & 2 (Editor)
        $this->assertCount(2, $users[0]['roles']);
        $this->assertSame('Admin', $users[0]['roles'][0]['name']);
        $this->assertSame(99, (int) $users[0]['roles'][0]['pivot']['assigned_by']);
        $this->assertSame('Super', $users[0]['roles'][0]['pivot']['role_label']);
        $this->assertSame('2026-01-01 00:00:00', $users[0]['roles'][0]['pivot']['created_at']);

        // User 2 has role 3 (Viewer)
        $this->assertCount(1, $users[1]['roles']);
        $this->assertSame('Viewer', $users[1]['roles'][0]['name']);

        // User 3 has no roles
        $this->assertSame([], $users[2]['roles']);
    }

    public function testBelongsToManyWithPivotObjectOnEntities(): void
    {
        $users = (new UserEntityModel())->with('roles')->orderBy('id', 'ASC')->findAll();

        // RoleModel returns array, so pivot is array; test with object returnType via asObject
        $userObj = (new UserEntityModel())->with(['roles' => fn ($q) => $q->asObject()])->find(1);
        $this->assertIsObject($userObj->roles[0]->pivot);
        $this->assertSame('Super', $userObj->roles[0]->pivot->role_label);
        $this->assertSame('Super', $userObj->roles[0]->pivot['role_label']);
    }

    public function testBelongsToManyWithWherePivotAndOrderByPivot(): void
    {
        $users = (new UserModel())->with([
            'roles' => fn ($q) => $q->wherePivot('role_label', 'Writer'),
        ])->orderBy('id', 'ASC')->findAll();

        $this->assertCount(1, $users[0]['roles']);
        $this->assertSame('Editor', $users[0]['roles'][0]['name']);
        $this->assertSame([], $users[1]['roles']);
    }

    public function testBelongsToManyInverse(): void
    {
        $roles = (new RoleModel())->with('users')->orderBy('id', 'ASC')->findAll();

        $this->assertCount(1, $roles[0]['users']);
        $this->assertSame('Alice', $roles[0]['users'][0]['name']);
        $this->assertSame('Super', $roles[0]['users'][0]['pivot']['role_label']);
    }

    public function testBelongsToManyAttachDetachSyncToggleUpdatePivot(): void
    {
        $userModel = new UserModel();
        $user3     = $userModel->find(3);

        // Attach
        $userModel->roles()->for($user3)->attach([1 => ['role_label' => 'Lead'], 2 => ['role_label' => 'Helper']]);
        $loaded = $userModel->with('roles')->find(3);
        $this->assertCount(2, $loaded['roles']);
        $this->assertNotEmpty($loaded['roles'][0]['pivot']['created_at']);

        // Update existing pivot
        $userModel->roles()->for($user3)->updateExistingPivot(1, ['role_label' => 'Owner']);
        $loaded = $userModel->with('roles')->find(3);
        $this->assertSame('Owner', $loaded['roles'][0]['pivot']['role_label']);

        // Sync
        $res = $userModel->roles()->for($user3)->sync([2 => ['role_label' => 'UpdatedHelper'], 3]);
        $this->assertSame([3], $res['attached']);
        $this->assertEquals([1], $res['detached']);
        $this->assertEquals([2], $res['updated']);

        $loaded = $userModel->with('roles')->find(3);
        $names  = array_column($loaded['roles'], 'name');
        sort($names);
        $this->assertSame(['Editor', 'Viewer'], $names);

        // Toggle
        $toggled = $userModel->roles()->for($user3)->toggle([1, 2]);
        $this->assertSame([1], $toggled['attached']);
        $this->assertSame([2], $toggled['detached']);

        // Detach all
        $userModel->roles()->for($user3)->detach();
        $loaded = $userModel->with('roles')->find(3);
        $this->assertSame([], $loaded['roles']);
    }

    public function testSelfReferencingBelongsToAndHasMany(): void
    {
        $cats = (new CategoryModel())->with('parent', 'children.children')->orderBy('id', 'ASC')->findAll();

        // Electronics (id=1): no parent, children = [Phones(2), Laptops(3)], Phones has child Smartphones(4)
        $this->assertNull($cats[0]['parent']);
        $this->assertCount(2, $cats[0]['children']);
        $this->assertSame('Smartphones', $cats[0]['children'][0]['children'][0]['name']);

        // Phones (id=2): parent = Electronics
        $this->assertSame('Electronics', $cats[1]['parent']['name']);
    }
}
