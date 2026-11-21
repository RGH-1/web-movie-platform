<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = '/api/v1/admin/reviews';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_guests_and_normal_users_cannot_moderate(): void
    {
        $review = $this->makeReview(Movie::factory()->create(), 4);

        $payload = [
            'status' => 'hidden',
            'reason' => 'Not permitted.',
        ];

        $this->getJson(self::INDEX)->assertUnauthorized();
        $this->patchJson($this->moderationUrl($review), $payload)
            ->assertUnauthorized();

        $this->signIn(User::factory()->create());

        $this->getJson(self::INDEX)->assertForbidden();
        $this->patchJson($this->moderationUrl($review), $payload)
            ->assertForbidden();

        // Authorization must also reject malformed action bodies.
        $this->patchJson($this->moderationUrl($review), [])
            ->assertForbidden();

        $this->assertSame(
            Review::STATUS_VISIBLE,
            $review->refresh()->moderation_status,
        );
        $this->assertNull($review->moderated_by);
        $this->assertNull($review->moderated_at);
        $this->assertNull($review->moderation_reason);
    }

    public function test_admin_listing_is_paginated_filterable_and_private(): void
    {
        $movie = Movie::factory()->create();

        $first = $this->makeReview($movie, 2);
        $second = $this->makeReview($movie, 3);

        $inactiveMovie = Movie::factory()->create([
            'deactivated_at' => now(),
        ]);

        $hidden = $this->makeReview($inactiveMovie, 4);
        $hidden->forceFill([
            'moderation_status' => Review::STATUS_HIDDEN,
        ])->save();

        $this->signIn($this->admin());

        $this->getJson(self::INDEX.'?per_page=2')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.id', (string) $hidden->id)
            ->assertJsonPath('data.0.movie.is_active', false)
            ->assertJsonPath('data.0.moderation.status', 'hidden')
            ->assertJsonMissingPath('data.0.author.email')
            ->assertJsonMissingPath('data.0.author.password')
            ->assertJsonMissingPath('data.0.sentiment_text_hash');

        $this->getJson(self::INDEX.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $first->id);

        $this->getJson(self::INDEX.'?status=hidden')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $hidden->id);

        $this->getJson(self::INDEX.'?status=visible')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $second->id);

        $this->getJson(self::INDEX.'?page=99')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_admin_listing_validates_query_parameters(): void
    {
        $this->signIn($this->admin());

        foreach ([
            ['page=0', 'page'],
            ['page=abc', 'page'],
            ['page=2147483648', 'page'],
            ['per_page=0', 'per_page'],
            ['per_page=51', 'per_page'],
            ['status=deleted', 'status'],
        ] as [$query, $field]) {
            $this->getJson(self::INDEX.'?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_hide_and_restore_record_the_acting_moderator(): void
    {
        $this->travelTo(now()->startOfSecond());

        $review = $this->makeReview(Movie::factory()->create(), 4);
        $originalText = $review->review_text;

        $firstAdmin = $this->admin();
        $secondAdmin = $this->admin();

        $this->signIn($firstAdmin);

        $this->patchJson($this->moderationUrl($review), [
            'status' => 'hidden',
            'reason' => '  Contains spoilers.  ',
        ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.moderation.status', 'hidden')
            ->assertJsonPath('data.moderation.reason', 'Contains spoilers.')
            ->assertJsonPath(
                'data.moderation.moderator.id',
                (string) $firstAdmin->id,
            )
            ->assertJsonPath(
                'data.moderation.moderated_at',
                now()->toISOString(),
            )
            ->assertJsonMissingPath('data.moderation.moderator.email');

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'moderation_status' => 'hidden',
            'moderated_by' => $firstAdmin->id,
            'moderation_reason' => 'Contains spoilers.',
            'moderated_at' => now()->toDateTimeString(),
        ]);

        $this->travelTo(now()->addMinute());
        $this->signIn($secondAdmin);

        $this->patchJson($this->moderationUrl($review), [
            'status' => 'visible',
            'reason' => 'Restored after review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.moderation.status', 'visible')
            ->assertJsonPath(
                'data.moderation.moderator.id',
                (string) $secondAdmin->id,
            );

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'moderation_status' => 'visible',
            'moderated_by' => $secondAdmin->id,
            'moderation_reason' => 'Restored after review.',
            'moderated_at' => now()->toDateTimeString(),
        ]);

        $review->refresh();

        $this->assertSame($originalText, $review->review_text);
        $this->assertSame(4, $review->rating);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_repeated_status_preserves_existing_audit_metadata(): void
    {
        $this->travelTo(now()->startOfSecond());

        $review = $this->makeReview(Movie::factory()->create(), 4);
        $firstAdmin = $this->admin();

        $this->signIn($firstAdmin);

        $this->patchJson($this->moderationUrl($review), [
            'status' => 'hidden',
            'reason' => 'Original moderation reason.',
        ])->assertOk();

        $original = $review->refresh()->getRawOriginal();

        $this->travelTo(now()->addMinute());
        $this->signIn($this->admin());

        $this->patchJson($this->moderationUrl($review), [
            'status' => 'hidden',
            'reason' => 'Repeated request.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.moderation.moderator.id',
                (string) $firstAdmin->id,
            )
            ->assertJsonPath(
                'data.moderation.reason',
                'Original moderation reason.',
            );

        $this->assertSame(
            $original,
            $review->refresh()->getRawOriginal(),
        );
    }

    public function test_invalid_actions_do_not_change_the_review(): void
    {
        $review = $this->makeReview(Movie::factory()->create(), 4);
        $original = $review->refresh()->getRawOriginal();

        $this->signIn($this->admin());

        foreach ([
            [[], ['status', 'reason']],
            [['status' => 'deleted', 'reason' => 'Reason'], ['status']],
            [['status' => 'hidden'], ['reason']],
            [['status' => 'hidden', 'reason' => '   '], ['reason']],
            [['status' => 'visible', 'reason' => null], ['reason']],
            [
                ['status' => 'hidden', 'reason' => str_repeat('x', 1001)],
                ['reason'],
            ],
            [
                [
                    'status' => 'hidden',
                    'reason' => 'Reason',
                    'moderated_by' => 999,
                    'moderated_at' => '2000-01-01',
                    'rating' => 1,
                    'user_id' => 999,
                ],
                ['moderated_by', 'moderated_at', 'rating', 'user_id'],
            ],
        ] as [$payload, $fields]) {
            $this->patchJson($this->moderationUrl($review), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($fields);

            $this->assertSame(
                $original,
                $review->refresh()->getRawOriginal(),
            );
        }
    }

    public function test_missing_or_malformed_review_ids_return_404(): void
    {
        $this->signIn($this->admin());

        foreach (['0', '01', 'abc', '999999', '9223372036854775808'] as $id) {
            $this->patchJson(self::INDEX.'/'.$id.'/moderation', [
                'status' => 'hidden',
                'reason' => 'Reason',
            ])->assertNotFound();
        }
    }

    public function test_moderation_updates_public_reviews_profiles_and_averages(): void
    {
        $movie = Movie::factory()->create();
        $low = $this->makeReview($movie, 2);
        $high = $this->makeReview($movie, 4);
        $publicUrl = "/api/v1/catalog/movies/{$movie->id}/reviews";
        $profileUrl = "/api/v1/users/{$high->user_id}/profile";

        $this->signIn($this->admin());

        $this->getJson($publicUrl)
            ->assertOk()
            ->assertJsonPath('meta.rating_count', 2)
            ->assertJsonPath('meta.rating_average', 3);

        foreach ([
            [$high, 'hidden', 1, 2, 0],
            [$low, 'hidden', 0, null, 0],
            [$high, 'visible', 1, 4, 1],
            [$low, 'visible', 2, 3, 1],
        ] as [$review, $status, $count, $average, $profileCount]) {
            $this->patchJson($this->moderationUrl($review), [
                'status' => $status,
                'reason' => 'Visibility transition test.',
            ])->assertOk();

            // Public responses remain filtered even for an administrator.
            $public = $this->getJson($publicUrl)
                ->assertOk()
                ->assertJsonCount($count, 'data')
                ->assertJsonPath('meta.total', $count)
                ->assertJsonPath('meta.rating_count', $count)
                ->assertJsonPath('meta.rating_average', $average);

            foreach ($public->json('data') as $entry) {
                $this->assertArrayNotHasKey('moderation', $entry);
                $this->assertArrayNotHasKey('moderated_by', $entry);
                $this->assertArrayNotHasKey('moderation_reason', $entry);
            }

            $profile = $this->getJson($profileUrl)
                ->assertOk()
                ->assertJsonPath(
                    'data.statistics.reviews_count',
                    $profileCount,
                )
                ->assertJsonCount($profileCount, 'data.reviews');

            if ($profileCount === 0) {
                $profile->assertJsonMissing([
                    'review_text' => $high->review_text,
                ]);
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_admin_role_does_not_override_owner_edit_or_delete_rules(): void
    {
        $review = $this->makeReview(Movie::factory()->create(), 4);

        $this->signIn($this->admin());

        $this->patchJson("/api/v1/reviews/{$review->id}", [
            'rating' => 1,
        ])->assertForbidden();

        $this->deleteJson("/api/v1/reviews/{$review->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 4,
            'moderation_status' => 'visible',
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function signIn(User $user): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function makeReview(Movie $movie, int $rating): Review
    {
        $author = User::factory()->create();

        $review = $author->reviews()->make([
            'rating' => $rating,
            'review_text' => 'Review by '.$author->id,
        ]);

        $review->movie()->associate($movie);
        $review->save();

        return $review;
    }

    private function moderationUrl(Review $review): string
    {
        return self::INDEX.'/'.$review->id.'/moderation';
    }
}