<?php

namespace Tests\Feature;

use App\Models\Movie;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MovieCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_local_movies_have_distinct_canonical_ids_and_no_provider_data(): void
    {
        $first = Movie::factory()->create()->refresh();
        $second = Movie::factory()->create()->refresh();

        $this->assertNotSame($first->id, $second->id);
        $this->assertIsString($first->id);
        $this->assertNull($first->tmdb_id);
        $this->assertNull($second->tmdb_id);
        foreach (
            [
                'overview',
                'release_date',
                'runtime_minutes',
                'poster_url',
                'backdrop_url',
                'director',
                'vote_average',
                'trailer',
                'tmdb_synced_at',
                'deactivated_at'
            ] as $field
        ) {
            $this->assertNull($first->$field);
        }
        $this->assertSame([], $first->genres);
        $this->assertSame([], $first->cast);
        $this->assertSame([], $first->metadata_overrides);
    }

    public function test_tmdb_snapshot_round_trips_with_casts_and_separate_provider_rating(): void
    {
        $movie = Movie::factory()->tmdb(157336)->create([
            'title' => ' Interstellar ',
            'overview' => 'Synopsis',
            'release_date' => '2014-11-05',
            'runtime_minutes' => 169,
            'genres' => [['tmdb_id' => 878, 'name' => 'Science Fiction']],
            'cast' => [['tmdb_person_id' => 10297, 'name' => 'Matthew', 'character' => null, 'profile_url' => null]],
            'vote_average' => 8.456,
            'trailer' => ['site' => 'YouTube', 'key' => 'zSWdZVtXT7E'],
            'metadata_overrides' => ['overview'],
        ])->refresh();

        $this->assertSame('Interstellar', $movie->title);
        $this->assertNotSame($movie->id, (string) $movie->tmdb_id);
        $this->assertSame(157336, $movie->tmdb_id);
        $this->assertSame(169, $movie->runtime_minutes);
        $this->assertSame('2014-11-05', $movie->toArray()['release_date']);
        $this->assertSame(8.456, $movie->toArray()['vote_average']);
        $this->assertSame('Science Fiction', $movie->genres[0]['name']);
        $this->assertSame('Matthew', $movie->cast[0]['name']);
        $this->assertSame('zSWdZVtXT7E', $movie->trailer['key']);
        $this->assertSame(['overview'], $movie->metadata_overrides);
        $this->assertInstanceOf(\DateTimeInterface::class, $movie->tmdb_synced_at);
        $this->assertArrayNotHasKey('rating', $movie->getAttributes());
    }

    public function test_database_prevents_duplicate_tmdb_ids(): void
    {
        Movie::factory()->tmdb(157336)->create();
        $this->expectException(QueryException::class);
        Movie::factory()->tmdb(157336)->create();
    }

    public function test_invalid_source_identity_and_numeric_values_are_rejected(): void
    {
        foreach (
            [
                ['source' => 'unknown'],
                ['source' => 'tmdb'],
                ['source' => 'tmdb', 'tmdb_id' => 0],
                ['source' => 'tmdb', 'tmdb_id' => 2147483648],
                ['source' => 'tmdb', 'tmdb_id' => 1.5],
                ['source' => 'local', 'tmdb_id' => 42],
                ['source' => 'local', 'vote_average' => 5],
                ['source' => 'local', 'tmdb_synced_at' => now()],
                ['runtime_minutes' => 0],
                ['runtime_minutes' => 1.5],
                ['source' => 'tmdb', 'tmdb_id' => 42, 'vote_average' => 10.001],
                ['title' => '   '],
            ] as $invalid
        ) {
            try {
                Movie::factory()->create($invalid);
                $this->fail('Invalid catalog values were saved.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('movies', 0);
            }
        }
    }

    public function test_existing_source_identity_cannot_be_changed(): void
    {
        $movie = Movie::factory()->tmdb(157336)->create();
        foreach (['tmdb_id' => 603, 'source' => 'local'] as $field => $value) {
            $candidate = $movie->fresh();
            $candidate->$field = $value;
            try {
                $candidate->save();
                $this->fail('An existing movie identity changed.');
            } catch (ValidationException) {
                $this->assertSame(157336, $movie->fresh()->tmdb_id);
                $this->assertSame('tmdb', $movie->fresh()->source);
            }
        }
    }

    public function test_deactivation_and_reactivation_preserve_the_catalog_identity(): void
    {
        $movie = Movie::factory()->tmdb(42)->create();
        $id = $movie->id;
        $movie->update(['deactivated_at' => now()]);
        $this->assertFalse(Movie::active()->whereKey($id)->exists());
        $this->assertTrue(Movie::whereKey($id)->exists());
        $this->assertInstanceOf(\DateTimeInterface::class, $movie->fresh()->deactivated_at);
        $movie->update(['deactivated_at' => null]);
        $this->assertTrue(Movie::active()->whereKey($id)->exists());
        $this->assertSame(42, $movie->fresh()->tmdb_id);
    }

    public function test_local_metadata_accepts_people_and_genres_without_provider_ids(): void
    {
        $movie = Movie::factory()->create([
            'genres' => [['tmdb_id' => null, 'name' => 'Student film']],
            'cast' => [['tmdb_person_id' => null, 'name' => 'Student Actor', 'character' => null, 'profile_url' => null]],
        ])->refresh();
        $this->assertNull($movie->genres[0]['tmdb_id']);
        $this->assertNull($movie->cast[0]['tmdb_person_id']);
    }

    public function test_relationship_keys_follow_the_erd_without_creating_other_domains(): void
    {
        // Test doubles stand in for the G/H/I models until their work items ship.
        $movie = new class extends Movie
        {

            protected function newRelatedInstance($class)
            {
                $related = new class extends Model {};

                $related->setTable(match ($class) {
                    'App\\Models\\Review' => 'reviews',
                    'App\\Models\\UserMovieStatus' => 'user_movie_statuses',
                    'App\\Models\\CustomList' => 'custom_lists',
                });

                return $related;
            }
        };
        $movie->setTable('movies');
        $movie->id = '123';
        $this->assertSame('reviews.movie_id', $movie->reviews()->getQualifiedForeignKeyName());
        $this->assertSame('user_movie_statuses.movie_id', $movie->userStatuses()->getQualifiedForeignKeyName());
        $this->assertSame('list_movies', $movie->customLists()->getTable());
        $this->assertSame('movie_id', $movie->customLists()->getForeignPivotKeyName());
        $this->assertSame('list_id', $movie->customLists()->getRelatedPivotKeyName());
    }

    public function test_mysql_rejects_invalid_identity_even_when_model_validation_is_bypassed(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Production CHECK constraints are verified on MySQL/MariaDB.');
        }
        $this->expectException(QueryException::class);
        DB::table('movies')->insert([
            'source' => 'local',
            'tmdb_id' => 42,
            'title' => 'Invalid identity',
            'genres' => '[]',
            'cast' => '[]',
            'metadata_overrides' => '[]',
        ]);
    }
}
