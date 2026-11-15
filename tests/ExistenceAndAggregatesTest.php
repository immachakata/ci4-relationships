<?php

namespace Tests;

use Tests\Fixtures\CategoryModel;
use Tests\Fixtures\CountryModel;
use Tests\Fixtures\PostModel;
use Tests\Fixtures\UserEntityModel;
use Tests\Fixtures\UserModel;

class ExistenceAndAggregatesTest extends DatabaseTestCase
{
    public function testHasDoesntHaveWhereHasWhereDoesntHave(): void
    {
        // Users with >= 1 non-deleted post: Alice (2), Bob (1)
        $usersWithPosts = (new UserModel())->has('posts')->orderBy('id', 'ASC')->findAll();
        $this->assertSame(['Alice', 'Bob'], array_column($usersWithPosts, 'name'));

        // Users with >= 2 posts: Alice only
        $prolific = (new UserModel())->has('posts', '>=', 2)->findAll();
        $this->assertSame(['Alice'], array_column($prolific, 'name'));

        // Users with no posts: Charlie
        $noPosts = (new UserModel())->doesntHave('posts')->findAll();
        $this->assertSame(['Charlie'], array_column($noPosts, 'name'));

        // whereHas with views > 150: Bob (post 4 has 200 views; Alice's deleted post 3 has 999 views and must be ignored!)
        $popular = (new UserModel())->whereHas('posts', fn ($q) => $q->where('views >', 150))->findAll();
        $this->assertSame(['Bob'], array_column($popular, 'name'));

        // whereDoesntHave published posts: Charlie
        $noPublished = (new UserModel())->whereDoesntHave('posts', fn ($q) => $q->where('status', 'published'))->findAll();
        $this->assertSame(['Charlie'], array_column($noPublished, 'name'));
    }

    public function testOrHasAndOrWhereHas(): void
    {
        // Users who have a profile OR have role 3 (Viewer)
        $users = (new UserModel())
            ->where('id', 3)
            ->orWhereHas('posts', fn ($q) => $q->where('views', 200))
            ->orderBy('id', 'ASC')
            ->findAll();

        $this->assertSame(['Bob', 'Charlie'], array_column($users, 'name'));
    }

    public function testNestedWhereHas(): void
    {
        $postModel = new PostModel();
        $post1     = $postModel->find(1);
        $postModel->comments()->for($post1)->create(['user_id' => 2, 'body' => 'Nice', 'approved' => 1]);

        // Countries that have users whose posts have approved comments -> Zimbabwe only
        $countries = (new CountryModel())
            ->whereHas('users.posts.comments', fn ($q) => $q->where('approved', 1))
            ->findAll();

        $this->assertSame(['Zimbabwe'], array_column($countries, 'name'));
    }

    public function testSelfReferencingWhereHas(): void
    {
        // Categories that have active children: Electronics (has Phones active=1) and Phones (has Smartphones active=1)
        $cats = (new CategoryModel())
            ->whereHas('children', fn ($q) => $q->where('active', 1))
            ->orderBy('id', 'ASC')
            ->findAll();

        $this->assertSame(['Electronics', 'Phones'], array_column($cats, 'name'));
    }

    public function testWithCountSumAvgMinMaxExistsAndOrderByCount(): void
    {
        $users = (new UserModel())
            ->withCount([
                'posts',
                'posts as published_posts_count' => fn ($q) => $q->where('status', 'published'),
            ])
            ->withSum('posts', 'views')
            ->withAvg('posts', 'views')
            ->withMin('posts', 'views')
            ->withMax('posts', 'views')
            ->withExists('profile')
            ->orderBy('posts_count', 'DESC')
            ->orderBy('id', 'ASC')
            ->findAll();

        // Alice: 2 posts (1 published), views 100 + 50 = 150, avg 75, min 50, max 100, has profile
        $this->assertSame('Alice', $users[0]['name']);
        $this->assertSame(2, $users[0]['posts_count']);
        $this->assertSame(1, $users[0]['published_posts_count']);
        $this->assertSame(150, $users[0]['posts_sum_views']);
        $this->assertEquals(75.0, $users[0]['posts_avg_views']);
        $this->assertSame(50, (int) $users[0]['posts_min_views']);
        $this->assertSame(100, (int) $users[0]['posts_max_views']);
        $this->assertTrue($users[0]['profile_exists']);

        // Charlie: 0 posts, null sum/avg/min/max, false profile_exists
        $this->assertSame('Charlie', $users[2]['name']);
        $this->assertSame(0, $users[2]['posts_count']);
        $this->assertNull($users[2]['posts_sum_views']);
        $this->assertFalse($users[2]['profile_exists']);
    }

    public function testWithCountOnEntitiesDoesNotDirtyEntity(): void
    {
        $alice = (new UserEntityModel())->withCount('posts')->withExists('profile')->find(1);

        $this->assertSame(2, $alice->posts_count);
        $this->assertTrue($alice->profile_exists);
        $this->assertFalse($alice->hasChanged());
    }

    public function testLoadCountSumAvgMinMaxExists(): void
    {
        $userModel = new UserModel();
        $users     = $userModel->orderBy('id', 'ASC')->findAll();

        $userModel->loadCount($users, ['posts', 'roles']);
        $userModel->loadSum($users, 'posts', 'views');
        $userModel->loadExists($users, 'profile');

        $this->assertSame(2, $users[0]['posts_count']);
        $this->assertSame(2, $users[0]['roles_count']);
        $this->assertSame(150, $users[0]['posts_sum_views']);
        $this->assertTrue($users[0]['profile_exists']);
        $this->assertFalse($users[2]['profile_exists']);
    }

    public function testThroughAndBelongsToManyWhereHasAndWithCount(): void
    {
        $countries = (new CountryModel())->withCount('posts')->orderBy('id', 'ASC')->findAll();
        $this->assertSame(3, $countries[0]['posts_count']);
        $this->assertSame(0, $countries[1]['posts_count']);

        $admins = (new UserModel())->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->findAll();
        $this->assertSame(['Alice'], array_column($admins, 'name'));
    }
}
