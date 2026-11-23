<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicActivityPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = '/api/v1/me/profile';

    public function test_application_and_database_defaults_are_private(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $this->activities($author);
        $viewer->following()->attach($author->id);

        $this->as($author);
        $profile = $this->getJson(self::PROFILE)->assertOk();

        foreach (User::ACTIVITY_SHARING_FIELDS as $field) {
            $this->assertFalse($author->fresh()->{$field});
            $profile->assertJsonPath('data.'.$field, false);
        }

        $id = DB::table('users')->insertGetId([
            'name' => 'Database defaults',
            'email' => 'database-defaults@example.test',
            'password' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rawUser = User::findOrFail($id);

        foreach (User::ACTIVITY_SHARING_FIELDS as $field) {
            $this->assertFalse($rawUser->{$field});
        }

        $this->as($viewer);
        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);

        $this->as(null);
        $this->getJson($this->publicUrl($author))
            ->assertOk()
            ->assertJsonPath('data.statistics', [
                'reviews_count' => 0,
                'public_lists_count' => 0,
            ]);
    }

    public function test_only_the_owner_can_change_preferences_and_omission_preserves_values(): void
    {
        $owner = User::factory()->sharingAllActivity()->create();
        $other = User::factory()->sharingAllActivity()->create();
        $originalOther = $other->fresh()->getRawOriginal();

        $this->as($owner);

        $this->patchJson(
            self::PROFILE.'?id='.$other->id.'&user_id='.$other->id,
            ['share_reviews' => false],
        )->assertOk()
            ->assertJsonPath('data.id', $owner->id)
            ->assertJsonPath('data.share_reviews', false)
            ->assertJsonPath('data.share_ratings', true)
            ->assertJsonPath('data.share_public_lists', true)
            ->assertJsonPath('data.share_activity', true);

        $this->getJson(self::PROFILE)
            ->assertOk()
            ->assertJsonPath('data.share_reviews', false);

        $this->assertSame($originalOther, $other->fresh()->getRawOriginal());

        $originalOwner = $owner->fresh()->getRawOriginal();

        $this->patchJson(self::PROFILE, [
            'user_id' => $other->id,
            'share_reviews' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertSame($originalOwner, $owner->fresh()->getRawOriginal());
        $this->assertSame($originalOther, $other->fresh()->getRawOriginal());

        $this->as(null);
        $this->patchJson(self::PROFILE, [
            'share_activity' => true,
        ])->assertUnauthorized();
    }

    public function test_invalid_preferences_reject_the_entire_update(): void
    {
        $owner = User::factory()->create(['name' => 'Original']);
        $original = $owner->fresh()->getRawOriginal();
        $this->as($owner);

        foreach (User::ACTIVITY_SHARING_FIELDS as $field) {
            foreach ([null, 1, 0, 'true', 'false', '1', [], ['value']] as $value) {
                $this->patchJson(self::PROFILE, [
                    'name' => 'Must not save',
                    $field => $value,
                ])->assertUnprocessable()
                    ->assertJsonValidationErrors($field);

                $this->assertSame(
                    $original,
                    $owner->fresh()->getRawOriginal(),
                );
            }
        }
    }

    public function test_all_category_combinations_filter_feed_and_public_profile(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->sharingAllActivity()->create();
        $viewer->following()->attach($author->id);
        $activity = $this->activities($author);

        // A sharing outsider must still be excluded.
        $outsider = User::factory()->sharingAllActivity()->create();
        $this->activities($outsider);

        for ($mask = 0; $mask < 8; $mask++) {
            $ratings = (bool) ($mask & 1);
            $reviews = (bool) ($mask & 2);
            $lists = (bool) ($mask & 4);

            $this->as($author);
            $this->patchJson(self::PROFILE, [
                'share_activity' => true,
                'share_ratings' => $ratings,
                'share_reviews' => $reviews,
                'share_public_lists' => $lists,
            ])->assertOk();

            $expectedFeed = [];
            $expectedReviews = [];

            if ($ratings) {
                $expectedFeed[] = 'rating:'.$activity['rating']->id;
                $expectedReviews[] = (string) $activity['rating']->id;
            }

            if ($reviews) {
                $expectedFeed[] = 'review:'.$activity['written']->id;
                $expectedReviews[] = (string) $activity['written']->id;
            }

            if ($lists) {
                $expectedFeed[] = 'public_list:'.$activity['public']->id;
            }

            $this->as($viewer);
            $feed = $this->getJson('/api/v1/feed?per_page=50')->assertOk();

            $actual = array_map(
                fn (array $item): string => $item['type'].':'.$item['target_id'],
                $feed->json('data'),
            );

            $this->assertEqualsCanonicalizing($expectedFeed, $actual);
            $feed->assertJsonPath('meta.total', count($expectedFeed));

            $this->as(null);
            $profile = $this->getJson($this->publicUrl($author))
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath(
                    'data.statistics.reviews_count',
                    count($expectedReviews),
                )
                ->assertJsonPath(
                    'data.statistics.public_lists_count',
                    $lists ? 1 : 0,
                )
                ->assertJsonCount($lists ? 1 : 0, 'data.public_lists');

            $this->assertEqualsCanonicalizing(
                $expectedReviews,
                array_column($profile->json('data.reviews'), 'id'),
            );

            foreach (User::ACTIVITY_SHARING_FIELDS as $field) {
                $profile->assertJsonMissingPath('data.'.$field);
            }

            $profile
                ->assertJsonMissing(['review_text' => 'Hidden review'])
                ->assertJsonMissing(['name' => 'Private collection']);
        }
    }

    public function test_master_switch_preserves_content_and_category_choices(): void
    {
        $author = User::factory()->sharingAllActivity()->create();
        $viewer = User::factory()->create();
        $viewer->following()->attach($author->id);
        $activity = $this->activities($author);

        $before = [];

        foreach (['reviews', 'movie_lists', 'movie_list_items'] as $table) {
            $before[$table] = DB::table($table)->get()
                ->map(fn ($row): array => (array) $row)->all();
        }

        $this->as($author);
        $this->patchJson(self::PROFILE, [
            'share_activity' => false,
        ])->assertOk()
            ->assertJsonPath('data.share_reviews', true)
            ->assertJsonPath('data.share_ratings', true)
            ->assertJsonPath('data.share_public_lists', true);

        $this->as($viewer);
        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        foreach ($before as $table => $rows) {
            $this->assertSame(
                $rows,
                DB::table($table)->get()
                    ->map(fn ($row): array => (array) $row)->all(),
            );
        }

        // The movie-page review is still public.
        $this->getJson(
            '/api/v1/catalog/movies/'.$activity['written']->movie_id.'/reviews',
        )->assertOk()
            ->assertJsonPath('meta.rating_count', 1)
            ->assertJsonPath('data.0.id', (string) $activity['written']->id);

        // Owner CRUD still works while activity sharing is disabled.
        $this->as($author);
        $this->patchJson('/api/v1/reviews/'.$activity['written']->id, [
            'rating' => 5,
        ])->assertOk();

        $this->patchJson('/api/v1/lists/'.$activity['public']->id, [
            'name' => 'Still owned',
        ])->assertOk();

        $this->patchJson(self::PROFILE, [
            'share_activity' => true,
        ])->assertOk();

        $this->as($viewer);
        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_privacy_filters_apply_before_pagination_and_preview_limits(): void
    {
        $author = User::factory()->create([
            'share_activity' => true,
            'share_reviews' => true,
        ]);
        $viewer = User::factory()->create();
        $viewer->following()->attach($author->id);

        for ($i = 0; $i < 11; $i++) {
            $this->review($author, 'Shared written review '.$i);
        }

        $this->travel(1)->minutes();

        for ($i = 0; $i < 15; $i++) {
            $this->review($author);
        }

        $this->as($viewer);
        $feed = $this->getJson('/api/v1/feed?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 11)
            ->assertJsonPath('meta.last_page', 6);

        foreach ($feed->json('data') as $item) {
            $this->assertSame('review', $item['type']);
        }

        $this->as(null);
        $this->getJson($this->publicUrl($author))
            ->assertOk()
            ->assertJsonCount(10, 'data.reviews')
            ->assertJsonPath('data.statistics.reviews_count', 11);
    }

    public function test_query_overrides_and_privileged_viewers_cannot_bypass_preferences(): void
    {
        $author = User::factory()->create();
        $this->activities($author);

        $admin = User::factory()->sharingAllActivity()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $admin->following()->attach($author->id);

        $spoof = '?share_activity=1&share_ratings=1&share_reviews=1&share_public_lists=1';

        foreach ([null, $author, $admin] as $viewer) {
            $this->as($viewer);
            $this->getJson($this->publicUrl($author).$spoof)
                ->assertOk()
                ->assertJsonPath('data.reviews', [])
                ->assertJsonPath('data.public_lists', [])
                ->assertJsonPath('data.statistics', [
                    'reviews_count' => 0,
                    'public_lists_count' => 0,
                ]);
        }

        $this->as($admin);
        $this->getJson('/api/v1/feed'.$spoof)
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    private function activities(User $author): array
    {
        $rating = $this->review($author);
        $written = $this->review($author, 'Shared written review');

        $hidden = $this->review($author, 'Hidden review');
        $hidden->forceFill([
            'moderation_status' => Review::STATUS_HIDDEN,
        ])->save();

        $this->review(
            $author,
            null,
            Movie::factory()->create(['deactivated_at' => now()]),
        );

        $public = $author->movieLists()->create([
            'name' => 'Public collection',
            'is_public' => true,
        ]);
        $public->movies()->attach($rating->movie_id);

        $private = $author->movieLists()->create([
            'name' => 'Private collection',
            'is_public' => false,
        ]);

        return compact('rating', 'written', 'public', 'private');
    }

    private function review(
        User $author,
        ?string $text = null,
        ?Movie $movie = null,
    ): Review {
        $review = $author->reviews()->make([
            'rating' => 4,
            'review_text' => $text,
        ]);

        $review->movie()->associate($movie ?? Movie::factory()->create());
        $review->save();

        return $review;
    }

    private function publicUrl(User $user): string
    {
        return "/api/v1/users/{$user->id}/profile";
    }

    private function as(?User $user): void
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        if ($user !== null) {
            $this->actingAs($user, 'web');
        }
    }
}