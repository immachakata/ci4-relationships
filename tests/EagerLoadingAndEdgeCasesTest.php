<?php

namespace Tests;

use InvalidArgumentException;
use Tests\Fixtures\CommentModel;
use Tests\Fixtures\CountryModel;
use Tests\Fixtures\DefaultWithUserModel;
use Tests\Fixtures\PostModel;
use Tests\Fixtures\UserModel;

class EagerLoadingAndEdgeCasesTest extends DatabaseTestCase
{
    public function testDeepNestedDotNotationWithConstraintsAndColumnSelection(): void
    {
        $postModel = new PostModel();
        $post1     = $postModel->find(1);
        $post4     = $postModel->find(4);

        $postModel->comments()->for($post1)->create(['user_id' => 2, 'body' => 'Approved comment', 'approved' => 1]);
        $postModel->comments()->for($post1)->create(['user_id' => 3, 'body' => 'Pending comment', 'approved' => 0]);
        $postModel->comments()->for($post4)->create(['user_id' => 1, 'body' => 'Bob post comment', 'approved' => 1]);

        $countries = (new CountryModel())->with([
            'users:id,country_id,name',
            'users.posts' => fn ($q) => $q->where('status', 'published'),
            'users.posts.comments' => fn ($q) => $q->where('approved', 1),
            'users.posts.comments.author:id,name',
        ])->orderBy('id', 'ASC')->findAll();

        $zimbabwe = $countries[0];
        $this->assertCount(2, $zimbabwe['users']);
        $this->assertArrayNotHasKey('email', $zimbabwe['users'][0]);
        $this->assertSame('Alice', $zimbabwe['users'][0]['name']);

        // Alice only has 1 published post (id=1)
        $this->assertCount(1, $zimbabwe['users'][0]['posts']);
        // Only approved comment (id=1) loaded on post 1
        $this->assertCount(1, $zimbabwe['users'][0]['posts'][0]['comments']);
        $this->assertSame('Bob', $zimbabwe['users'][0]['posts'][0]['comments'][0]['author']['name']);
        $this->assertArrayNotHasKey('email', $zimbabwe['users'][0]['posts'][0]['comments'][0]['author']);
    }

    public function testOrWhereInsideEagerLoadConstraintIsProperlyGrouped(): void
    {
        // Regression test: orWhere inside with() closure must NOT leak records belonging to other parents!
        $users = (new UserModel())->with([
            'posts' => fn ($q) => $q->where('status', 'published')->orWhere('status', 'draft'),
        ])->orderBy('id', 'ASC')->findAll();

        // User 3 has 0 posts; if OR leaked, User 3 or User 2 would receive Alice's posts or vice versa
        $this->assertCount(2, $users[0]['posts']);
        $this->assertCount(1, $users[1]['posts']);
        $this->assertCount(0, $users[2]['posts']);
    }

    public function testDefaultWithAndWithoutAndCircularGuard(): void
    {
        $users = (new DefaultWithUserModel())->orderBy('id', 'ASC')->findAll();
        $this->assertSame('Alice bio', $users[0]['profile']['bio']);
        $this->assertSame(2, $users[0]['posts_count']);

        // without('profile') suppresses profile only
        $usersNoProfile = (new DefaultWithUserModel())->without('profile')->orderBy('id', 'ASC')->findAll();
        $this->assertArrayNotHasKey('profile', $usersNoProfile[0]);
        $this->assertSame(2, $usersNoProfile[0]['posts_count']);

        // withoutRelations() suppresses all default $with and $withCount
        $plain = (new DefaultWithUserModel())->withoutRelations()->orderBy('id', 'ASC')->findAll();
        $this->assertArrayNotHasKey('profile', $plain[0]);
        $this->assertArrayNotHasKey('posts_count', $plain[0]);

        // Circular default $with guard: A -> $with=['b'], B -> $with=['a']
        $modelA = new class ($this->db) extends \CodeIgniter\Model {
            use \IsaacMachakata\Ci4Relationships\RelationshipsTrait;
            protected $table = 'users';
            protected $returnType = 'array';
            protected array $with = ['profile'];
            public function profile()
            {
                return $this->hasOne(CircularProfileModel::class, 'user_id');
            }
        };

        $row = $modelA->find(1);
        $this->assertSame('Alice bio', $row['profile']['bio']);
        // CircularProfileModel::$with=['user'] loaded user, and UserModel didn't infinite-loop!
        $this->assertSame('Alice', $row['profile']['user']['name']);
    }

    public function testEmptyAndMissingResultsDoNotError(): void
    {
        $this->assertSame([], (new UserModel())->with('profile', 'posts')->where('id', -1)->findAll());
        $this->assertNull((new UserModel())->with('profile', 'posts')->find(-1));
        $this->assertNull((new UserModel())->with('profile', 'posts')->where('id', -1)->first());
    }

    public function testPerQueryStateResetsAfterFind(): void
    {
        $userModel = new UserModel();
        $withPosts = $userModel->with('posts')->find(1);
        $this->assertArrayHasKey('posts', $withPosts);

        // Next call without with() should NOT carry over 'posts'
        $withoutPosts = $userModel->find(1);
        $this->assertArrayNotHasKey('posts', $withoutPosts);
    }

    public function testPaginateWithRelationships(): void
    {
        $userModel = new UserModel();
        $page1     = $userModel->with('profile')->withCount('posts')->orderBy('id', 'ASC')->paginate(2, 'default', 1);

        $this->assertCount(2, $page1);
        $this->assertSame('Alice bio', $page1[0]['profile']['bio']);
        $this->assertSame(2, $page1[0]['posts_count']);
        $this->assertSame(3, $userModel->pager->getTotal());
    }

    public function testLoadAndLoadMissingOnExistingRows(): void
    {
        $userModel = new UserModel();
        $users     = $userModel->orderBy('id', 'ASC')->findAll();

        // Lazy eager load onto collection
        $userModel->load($users, 'profile');
        $this->assertSame('Alice bio', $users[0]['profile']['bio']);

        // Mutate profile on user 1 to verify loadMissing doesn't overwrite already-loaded relations
        $users[0]['profile']['bio'] = 'Cached';
        unset($users[1]['profile']);

        $userModel->loadMissing($users, 'profile', 'posts');
        $this->assertSame('Cached', $users[0]['profile']['bio']);
        $this->assertSame('Bob bio', $users[1]['profile']['bio']);
        $this->assertCount(2, $users[0]['posts']);
    }

    public function testInvalidRelationThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UserModel())->with('nonExistentRelation')->findAll();
    }

    public function testFindWithArrayOfIdsAndClosureNestedWith(): void
    {
        $postModel = new PostModel();
        $post1     = $postModel->find(1);
        $postModel->comments()->for($post1)->create(['user_id' => 2, 'body' => 'Hello']);

        $users = (new UserModel())->with([
            'posts' => fn ($q) => $q->with('comments'),
        ])->find([1, 2]);

        $this->assertCount(2, $users);
        $this->assertCount(1, $users[0]['posts'][0]['comments']);
    }

    public function testWithCountAndSelectInBothOrdersAndMorphManyAggregates(): void
    {
        $postModel = new PostModel();
        $post1     = $postModel->find(1);
        $postModel->comments()->for($post1)->create(['user_id' => 2, 'body' => 'C1']);
        $postModel->comments()->for($post1)->create(['user_id' => 2, 'body' => 'C2']);

        $postsA = (new PostModel())->select('id, title')->withCount('comments')->orderBy('id', 'ASC')->findAll();
        $postsB = (new PostModel())->withCount('comments')->select('id, title')->orderBy('id', 'ASC')->findAll();

        $this->assertSame(2, $postsA[0]['comments_count']);
        $this->assertSame(2, $postsB[0]['comments_count']);
        $this->assertArrayNotHasKey('views', $postsA[0]);
        $this->assertArrayNotHasKey('views', $postsB[0]);

        // Also verify loadCount on morphMany
        $rawPosts = $postModel->orderBy('id', 'ASC')->findAll();
        $postModel->loadCount($rawPosts, 'comments');
        $this->assertSame(2, $rawPosts[0]['comments_count']);
        $this->assertSame(0, $rawPosts[1]['comments_count']);
    }

    public function testNoNPlusOneQueriesForNestedEagerLoading(): void
    {
        $postModel = new PostModel();
        foreach ($postModel->findAll() as $p) {
            $postModel->comments()->for($p)->create(['user_id' => 1, 'body' => 'C on ' . $p['id']]);
        }

        $queryCount = 0;
        $listener   = static function () use (&$queryCount): void {
            $queryCount++;
        };
        \CodeIgniter\Events\Events::on('DBQuery', $listener);

        try {
            $countries = (new CountryModel())->with('users.posts.comments.author')->findAll();
        } finally {
            \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
        }

        $this->assertCount(3, $countries);
        // Strictly 5 queries: countries + users + posts + comments + author (NOT N+1)
        $this->assertSame(5, $queryCount);
    }

    public function testEntityWithDatamap(): void
    {
        $mappedEntityClass = get_class(new class extends \CodeIgniter\Entity\Entity {
            use \IsaacMachakata\Ci4Relationships\EntityRelationshipsTrait;
            protected $datamap = [
                'authorId' => 'user_id',
            ];
        });

        $post = (new PostModel())->asObject($mappedEntityClass)->with('author')->find(1);
        $this->assertSame(1, (int) $post->authorId);
        $this->assertSame('Alice', $post->author['name']);
    }

    public function testLegacyCI4ExtensionsNamespaceBridgeStillWorks(): void
    {
        $legacyModel = new class ($this->db) extends \CodeIgniter\Model {
            use \CI4Extensions\Database\RelationshipsTrait;
            protected $table = 'users';
            protected $returnType = 'array';
            public function posts()
            {
                return $this->hasMany(\Tests\Fixtures\PostModel::class, 'user_id');
            }
        };

        $user = $legacyModel->with('posts')->find(1);
        $this->assertCount(2, $user['posts']);
    }
}

class CircularProfileModel extends \CodeIgniter\Model
{
    use \IsaacMachakata\Ci4Relationships\RelationshipsTrait;

    protected $table      = 'profiles';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected array $with = ['user'];

    public function user()
    {
        return $this->belongsTo(CircularUserModel::class, 'user_id');
    }
}

class CircularUserModel extends \CodeIgniter\Model
{
    use \IsaacMachakata\Ci4Relationships\RelationshipsTrait;

    protected $table      = 'users';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected array $with = ['profile'];

    public function profile()
    {
        return $this->hasOne(CircularProfileModel::class, 'user_id');
    }
}
