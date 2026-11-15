<?php

namespace Tests;

use IsaacMachakata\Ci4Relationships\Relations\Relation;
use Tests\Fixtures\CommentModel;
use Tests\Fixtures\CountryModel;
use Tests\Fixtures\ImageModel;
use Tests\Fixtures\PostModel;
use Tests\Fixtures\TagModel;
use Tests\Fixtures\VideoModel;

class ThroughAndPolymorphicTest extends DatabaseTestCase
{
    public function testHasManyThroughAndHasOneThrough(): void
    {
        $countries = (new CountryModel())->with('posts', 'latestPost')->orderBy('id', 'ASC')->findAll();

        // Country 1 (Zimbabwe) has Alice (posts 1, 2; post 3 deleted) and Bob (post 4) => 3 posts
        $this->assertCount(3, $countries[0]['posts']);
        $this->assertSame(4, (int) $countries[0]['latestPost']['id']);

        // Country 2 (Kenya) has Charlie (0 posts)
        $this->assertSame([], $countries[1]['posts']);
        $this->assertNull($countries[1]['latestPost']);

        // Country 3 (EmptyLand) has no users
        $this->assertSame([], $countries[2]['posts']);
    }

    public function testHasManyThroughRespectsSoftDeletesOnThroughModel(): void
    {
        // Soft-delete Bob (user 2)
        $this->db->table('users')->where('id', 2)->update(['deleted_at' => '2026-01-12 00:00:00']);

        $softUserCountryModel = new class ($this->db) extends CountryModel {
            public function activeUserPosts()
            {
                $softUserModel = new class ($this->db) extends \Tests\Fixtures\UserModel {
                    protected $useSoftDeletes = true;
                };

                return $this->hasManyThrough(PostModel::class, get_class($softUserModel), 'country_id', 'user_id');
            }
        };

        $country = $softUserCountryModel->with('activeUserPosts')->find(1);
        // Only Alice's 2 posts remain, Bob's post 4 is excluded because Bob is soft-deleted
        $this->assertCount(2, $country['activeUserPosts']);
    }

    public function testMorphOneMorphManyAndMorphTo(): void
    {
        $postModel  = new PostModel();
        $videoModel = new VideoModel();

        $post1  = $postModel->find(1);
        $video1 = $videoModel->find(1);

        $postModel->comments()->for($post1)->create(['user_id' => 2, 'body' => 'Great post!', 'approved' => 1]);
        $postModel->comments()->for($post1)->create(['user_id' => 1, 'body' => 'Spam', 'approved' => 0]);
        $videoModel->comments()->for($video1)->create(['user_id' => 1, 'body' => 'Nice video!', 'approved' => 1]);
        $postModel->image()->for($post1)->create(['url' => 'cover.jpg', 'is_primary' => 1]);

        $posts = $postModel->with('comments', 'image')->orderBy('id', 'ASC')->findAll();
        $this->assertCount(2, $posts[0]['comments']);
        $this->assertSame('cover.jpg', $posts[0]['image']['url']);
        $this->assertNull($posts[1]['image']);

        $comments = (new CommentModel())->with([
            'commentable' => fn ($morphTo) => $morphTo->morphWith([
                PostModel::class  => ['author'],
                VideoModel::class => ['user'],
            ]),
        ])->orderBy('id', 'ASC')->findAll();

        $this->assertCount(3, $comments);
        $this->assertSame('Alice Post 1', $comments[0]['commentable']['title']);
        $this->assertSame('Alice', $comments[0]['commentable']['author']['name']);
        $this->assertSame('Intro Video', $comments[2]['commentable']['title']);
        $this->assertSame('Alice', $comments[2]['commentable']['user']['name']);
    }

    public function testMorphMapAndEnforceMorphMap(): void
    {
        Relation::enforceMorphMap([
            'post'  => PostModel::class,
            'video' => VideoModel::class,
        ]);

        $postModel  = new PostModel();
        $videoModel = new VideoModel();

        $postModel->comments()->for($postModel->find(1))->create(['user_id' => 1, 'body' => 'Mapped post comment']);
        $videoModel->comments()->for($videoModel->find(1))->create(['user_id' => 2, 'body' => 'Mapped video comment']);

        // Verify raw DB stored alias 'post' / 'video'
        $raw = $this->db->table('comments')->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertSame('post', $raw[0]['commentable_type']);
        $this->assertSame('video', $raw[1]['commentable_type']);

        $comments = (new CommentModel())->with('commentable')->orderBy('id', 'ASC')->findAll();
        $this->assertSame('Alice Post 1', $comments[0]['commentable']['title']);
        $this->assertSame('Intro Video', $comments[1]['commentable']['title']);

        // Unmapped model should throw when enforceMorphMap is active
        $this->expectException(\InvalidArgumentException::class);
        $unmapped = new class ($this->db) extends \CodeIgniter\Model {
            use \IsaacMachakata\Ci4Relationships\RelationshipsTrait;
            protected $table = 'videos';
            public function img()
            {
                return $this->morphOne(ImageModel::class, 'imageable');
            }
        };
        $unmapped->img();
    }

    public function testMorphToManyAndMorphedByMany(): void
    {
        $this->db->table('tags')->insertBatch([
            ['id' => 1, 'name' => 'PHP'],
            ['id' => 2, 'name' => 'CI4'],
        ]);

        $postModel  = new PostModel();
        $videoModel = new VideoModel();

        $post1  = $postModel->find(1);
        $video1 = $videoModel->find(1);

        $postModel->tags()->for($post1)->attach([1, 2]);
        $videoModel->tags()->for($video1)->attach([2]);

        $loadedPost = $postModel->with('tags')->find(1);
        $this->assertCount(2, $loadedPost['tags']);

        $loadedTag = (new TagModel())->with('posts', 'videos')->find(2);
        $this->assertCount(1, $loadedTag['posts']);
        $this->assertCount(1, $loadedTag['videos']);
        $this->assertSame('Alice Post 1', $loadedTag['posts'][0]['title']);
        $this->assertSame('Intro Video', $loadedTag['videos'][0]['title']);

        // Sync on morphToMany shouldn't affect videos
        $postModel->tags()->for($post1)->sync([1]);
        $loadedTag2 = (new TagModel())->with('posts', 'videos')->find(2);
        $this->assertCount(0, $loadedTag2['posts']);
        $this->assertCount(1, $loadedTag2['videos']);
    }
}
