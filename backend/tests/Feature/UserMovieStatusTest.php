<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\UserMovieStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserMovieStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createMovie(
        string $title = 'Interstellar',
        int $tmdbId = 157336
    ): Movie {
        return Movie::create([
            'source' => 'tmdb',
            'tmdb_id' => $tmdbId,
            'title' => $title,
            'genres' => [],
            'cast' => [],
            'metadata_overrides' => [],
        ]);
    }

    public function test_watchlist_status_and_boolean_cast(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        $status = UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);

        $this->assertTrue($status->in_watchlist);
        $this->assertNull($status->watched_at);
        $this->assertFalse($status->isWatched());

        $this->assertDatabaseHas('user_movie_statuses', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);
    }

    public function test_watchlist_and_watched_are_independent(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        $status = UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);

        $status->update([
            'watched_at' => Carbon::parse('2026-11-14 10:30:00'),
        ]);

        $this->assertTrue($status->fresh()->in_watchlist);
        $this->assertTrue($status->fresh()->isWatched());
        $this->assertInstanceOf(Carbon::class, $status->fresh()->watched_at);

        $status->update(['in_watchlist' => false]);

        $this->assertDatabaseHas('user_movie_statuses', [
            'id' => $status->id,
            'in_watchlist' => false,
        ]);

        $this->assertTrue($status->fresh()->isWatched());

        $status->update([
            'in_watchlist' => true,
            'watched_at' => null,
        ]);

        $this->assertTrue($status->fresh()->in_watchlist);
        $this->assertFalse($status->fresh()->isWatched());
    }

    public function test_record_is_deleted_when_both_statuses_are_cleared(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        $status = UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
            'watched_at' => now(),
        ]);

        $status->update(['in_watchlist' => false]);

        $this->assertDatabaseHas('user_movie_statuses', [
            'id' => $status->id,
        ]);

        $status->update(['watched_at' => null]);

        $this->assertDatabaseMissing('user_movie_statuses', [
            'id' => $status->id,
        ]);
    }

    public function test_duplicate_user_movie_pair_is_rejected(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);

        $this->expectException(QueryException::class);

        UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);
    }

    public function test_relationships_work_correctly(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        $status = UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);

        $this->assertTrue($status->user->is($user));
        $this->assertTrue($status->movie->is($movie));

        $this->assertTrue(
            $user->movieStatuses()->whereKey($status->id)->exists()
        );

        $this->assertTrue(
            $movie->userStatuses()->whereKey($status->id)->exists()
        );
    }

    public function test_deleting_user_cascades_to_status_records(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        $status = UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);

        $user->delete();

        $this->assertDatabaseMissing('user_movie_statuses', [
            'id' => $status->id,
        ]);

        $this->assertDatabaseHas('movies', [
            'id' => $movie->id,
        ]);
    }


    public function test_empty_status_is_deleted_immediately(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => false,
            'watched_at' => null,
        ]);

        $this->assertDatabaseMissing('user_movie_statuses', [
            'user_id' => $user->id,
            'movie_id' => $movie->id,
        ]);
    }

    public function test_different_users_and_movies_have_independent_statuses(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $movie1 = $this->createMovie();
        $movie2 = $this->createMovie('The Martian', 286217);

        UserMovieStatus::create([
            'user_id' => $user1->id,
            'movie_id' => $movie1->id,
            'in_watchlist' => true,
        ]);

        UserMovieStatus::create([
            'user_id' => $user2->id,
            'movie_id' => $movie1->id,
            'in_watchlist' => true,
        ]);

        UserMovieStatus::create([
            'user_id' => $user1->id,
            'movie_id' => $movie2->id,
            'watched_at' => now(),
        ]);

        $this->assertDatabaseCount('user_movie_statuses', 3);

        $this->assertCount(2, $user1->movieStatuses);
        $this->assertCount(1, $user2->movieStatuses);

        $this->assertCount(2, $movie1->userStatuses);
        $this->assertCount(1, $movie2->userStatuses);
    }

    public function test_movie_with_status_records_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $movie = $this->createMovie();

        UserMovieStatus::create([
            'user_id' => $user->id,
            'movie_id' => $movie->id,
            'in_watchlist' => true,
        ]);

        $this->expectException(QueryException::class);

        $movie->delete();
    }
}
