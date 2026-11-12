<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_rating_only_review_has_canonical_relationships_and_safe_defaults(): void
    {
        $author = User::factory()->create();
        $movie = Movie::factory()->tmdb(157336)->create();

        $review = $this->createReview($author, $movie)->refresh();

        $this->assertSame(4, $review->rating);
        $this->assertNull($review->review_text);
        $this->assertSame(Review::STATUS_VISIBLE, $review->moderation_status);
        $this->assertNull($review->moderated_by);
        $this->assertNull($review->moderated_at);
        $this->assertNull($review->moderation_reason);
        $this->assertNull($review->moderator);
        $this->assertSame($movie->id, $review->movie_id);
        $this->assertTrue($review->user->is($author));
        $this->assertTrue($review->movie->is($movie));
        $this->assertTrue($author->reviews()->sole()->is($review));
        $this->assertTrue($movie->reviews()->sole()->is($review));
    }

    public function test_database_defaults_to_visible_when_moderation_fields_are_omitted(): void
    {
        $id = DB::table('reviews')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'movie_id' => Movie::factory()->create()->id,
            'rating' => 5,
        ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $id,
            'review_text' => null,
            'moderation_status' => Review::STATUS_VISIBLE,
            'moderated_by' => null,
            'moderated_at' => null,
            'moderation_reason' => null,
        ]);
    }

    public function test_database_allows_only_one_review_per_user_and_movie_pair(): void
    {
        $author = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->createReview($author, $movie);
        $this->createReview(User::factory()->create(), $movie);
        $this->createReview($author, Movie::factory()->create());

        $this->assertDatabaseCount('reviews', 3);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->createReview($author, $movie);
    }

    public function test_mass_assignment_cannot_set_ownership_or_moderation_fields(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $movie = Movie::factory()->create();
        $otherMovie = Movie::factory()->create();

        $forgedFields = [
            'user_id' => $otherUser->id,
            'movie_id' => $otherMovie->id,
            'moderation_status' => Review::STATUS_HIDDEN,
            'moderated_by' => $otherUser->id,
            'moderated_at' => now(),
            'moderation_reason' => 'Forged reason',
        ];

        $review = new Review($forgedFields + ['rating' => 3]);

        $this->assertNull($review->user_id);
        $this->assertNull($review->movie_id);
        $this->assertSame(Review::STATUS_VISIBLE, $review->moderation_status);

        $review->user()->associate($author);
        $review->movie()->associate($movie);
        $review->save();

        $review->update($forgedFields + [
            'rating' => 5,
            'review_text' => 'Updated review text.',
        ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $author->id,
            'movie_id' => $movie->id,
            'rating' => 5,
            'review_text' => 'Updated review text.',
            'moderation_status' => Review::STATUS_VISIBLE,
            'moderated_by' => null,
            'moderated_at' => null,
            'moderation_reason' => null,
        ]);
    }

    #[DataProvider('invalidRatings')]
    public function test_model_rejects_invalid_ratings(int|float|null $rating): void
    {
        $author = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->expectException(ValidationException::class);
        $this->createReview($author, $movie, ['rating' => $rating]);
    }

    public static function invalidRatings(): array
    {
        return [
            'missing rating' => [null],
            'below range' => [0],
            'above range' => [6],
            'fractional rating' => [2.5],
        ];
    }

    public function test_mysql_rejects_out_of_range_ratings_in_direct_sql(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The database rating CHECK is tested on MySQL/MariaDB.');
        }

        $author = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('reviews')->insert([
            'user_id' => $author->id,
            'movie_id' => $movie->id,
            'rating' => 6,
        ]);
    }

    public function test_moderator_deletion_clears_the_reference_and_author_deletion_removes_the_review(): void
    {
        $author = User::factory()->create();
        $moderator = User::factory()->create();
        $review = $this->createReview($author, Movie::factory()->create());

        $review->moderator()->associate($moderator);
        $review->moderation_status = Review::STATUS_HIDDEN;
        $review->moderated_at = now();
        $review->moderation_reason = 'Hidden after moderation.';
        $review->save();

        $this->assertTrue($review->refresh()->moderator->is($moderator));

        $moderator->delete();
        $review->refresh();

        $this->assertNull($review->moderated_by);
        $this->assertSame(Review::STATUS_HIDDEN, $review->moderation_status);
        $this->assertNotNull($review->moderated_at);
        $this->assertSame('Hidden after moderation.', $review->moderation_reason);

        $author->delete();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_deactivation_preserves_reviews_and_hard_movie_deletion_is_restricted(): void
    {
        $movie = Movie::factory()->create();
        $review = $this->createReview(User::factory()->create(), $movie);

        $movie->update(['deactivated_at' => now()]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'movie_id' => $movie->id,
        ]);

        $this->expectException(QueryException::class);
        $movie->delete();
    }

    private function createReview(
        User $author,
        Movie $movie,
        array $content = ['rating' => 4],
    ): Review {
        $review = $author->reviews()->make($content);
        $review->movie()->associate($movie);
        $review->save();

        return $review;
    }
}