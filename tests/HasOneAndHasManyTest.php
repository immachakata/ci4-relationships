<?php

namespace Tests;

use Tests\Fixtures\LegacyUserModel;
use Tests\Fixtures\TenantItemModel;
use Tests\Fixtures\TenantModel;
use Tests\Fixtures\UserEntity;
use Tests\Fixtures\UserEntityModel;
use Tests\Fixtures\UserModel;

class HasOneAndHasManyTest extends DatabaseTestCase
{
    public function testHasOneAndHasManyWithFindAllAsArrays(): void
    {
        $users = (new UserModel())->with('profile', 'posts')->orderBy('id', 'ASC')->findAll();

        $this->assertCount(3, $users);

        // User 1 has profile + 2 non-deleted posts
        $this->assertSame('Alice bio', $users[0]['profile']['bio']);
        $this->assertCount(2, $users[0]['posts']);

        // User 2 has profile + 1 post
        $this->assertSame('Bob bio', $users[1]['profile']['bio']);
        $this->assertCount(1, $users[1]['posts']);

        // User 3 has NO profile (null, not []) and NO posts ([])
        $this->assertNull($users[2]['profile']);
        $this->assertSame([], $users[2]['posts']);
    }

    public function testHasOneAndHasManyWithSingletonFindAndFirst(): void
    {
        $user = (new UserModel())->with('profile', 'posts')->find(1);
        $this->assertNotNull($user);
        $this->assertSame('Alice bio', $user['profile']['bio']);
        $this->assertCount(2, $user['posts']);

        $charlie = (new UserModel())->with('profile', 'posts')->where('id', 3)->first();
        $this->assertNotNull($charlie);
        $this->assertNull($charlie['profile']);
        $this->assertSame([], $charlie['posts']);
    }

    public function testHasOneAndHasManyWithAsObjectAndEntity(): void
    {
        $users = (new UserModel())->asObject()->with('profile', 'posts')->orderBy('id', 'ASC')->findAll();
        $this->assertIsObject($users[0]);
        $this->assertSame('Alice bio', $users[0]->profile['bio']);
        $this->assertCount(2, $users[0]->posts);
        $this->assertNull($users[2]->profile);
        $this->assertSame([], $users[2]->posts);

        $entities = (new UserEntityModel())->with('profile', 'posts')->orderBy('id', 'ASC')->findAll();
        $this->assertInstanceOf(UserEntity::class, $entities[0]);
        $this->assertSame('Alice bio', $entities[0]->profile['bio']);
        $this->assertCount(2, $entities[0]->posts);
        $this->assertNull($entities[2]->profile);
        $this->assertSame([], $entities[2]->posts);
    }

    public function testLegacyInitializeDefinedRelationships(): void
    {
        $users = (new LegacyUserModel())->with('avatar', 'links')->orderBy('id', 'ASC')->findAll();

        $this->assertSame('Alice bio', $users[0]['avatar']['bio']);
        $this->assertCount(2, $users[0]['links']);
        $this->assertNull($users[2]['avatar']);
        $this->assertSame([], $users[2]['links']);

        $single = (new LegacyUserModel())->with('avatar')->find(1);
        $this->assertSame('alice.png', $single['avatar']['avatar_url']);
    }

    public function testHasOneWithDefault(): void
    {
        $users = (new UserModel())->with('profileWithDefault')->orderBy('id', 'ASC')->findAll();

        $this->assertSame('Alice bio', $users[0]['profileWithDefault']['bio']);
        $this->assertSame('Default Bio', $users[2]['profileWithDefault']['bio']);
        $this->assertSame(3, $users[2]['profileWithDefault']['user_id']);
    }

    public function testOfManyLatestOldestAndCustomAggregate(): void
    {
        $users = (new UserModel())
            ->with('latestPost', 'oldestPost', 'topPost')
            ->orderBy('id', 'ASC')
            ->findAll();

        // User 1 posts: id=1 (views=100), id=2 (views=50); id=3 is soft-deleted!
        $this->assertSame(2, (int) $users[0]['latestPost']['id']);
        $this->assertSame(1, (int) $users[0]['oldestPost']['id']);
        $this->assertSame(1, (int) $users[0]['topPost']['id']);

        // User 2 post: id=4
        $this->assertSame(4, (int) $users[1]['latestPost']['id']);
        $this->assertSame(4, (int) $users[1]['oldestPost']['id']);

        // User 3 has no posts
        $this->assertNull($users[2]['latestPost']);

        // Direct query on latestPost
        $latest = (new UserModel())->latestPost()->for($users[0])->first();
        $this->assertSame(2, (int) $latest['id']);
    }

    public function testConstrainedRelationMethodOnModel(): void
    {
        $users = (new UserModel())->with('publishedPosts')->orderBy('id', 'ASC')->findAll();

        $this->assertCount(1, $users[0]['publishedPosts']);
        $this->assertSame('Alice Post 1', $users[0]['publishedPosts'][0]['title']);
        $this->assertCount(1, $users[1]['publishedPosts']);
    }

    public function testUuidStringPrimaryKeys(): void
    {
        $this->db->table('tenants')->insertBatch([
            ['uuid' => 't-aaa', 'name' => 'Tenant A'],
            ['uuid' => 't-bbb', 'name' => 'Tenant B'],
        ]);
        $this->db->table('tenant_items')->insertBatch([
            ['uuid' => 'i-1', 'tenant_uuid' => 't-aaa', 'label' => 'Item 1'],
            ['uuid' => 'i-2', 'tenant_uuid' => 't-aaa', 'label' => 'Item 2'],
        ]);

        $tenants = (new TenantModel())->with('items')->orderBy('uuid', 'ASC')->findAll();
        $this->assertCount(2, $tenants[0]['items']);
        $this->assertSame([], $tenants[1]['items']);

        $items = (new TenantItemModel())->with('tenant')->findAll();
        $this->assertSame('Tenant A', $items[0]['tenant']['name']);
    }
}
