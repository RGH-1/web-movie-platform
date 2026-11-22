<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Services\RecommendationGenreNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FavoriteGenrePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = '/api/v1/me/profile';

    private const MIGRATION =
        'migrations/2026_11_22_154049_normalize_favorite_genre_to_tmdb_ids.php';

    public function test_supported_movie_genre_ids_save_and_reload_as_integers(): void
    {
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $ids = [
            28, 12, 16, 35, 80, 99, 18, 10751, 14, 36,
            27, 10402, 9648, 10749, 878, 10770, 53, 10752, 37,
        ];

        foreach ($ids as $id) {
            $this->patchJson(self::PROFILE, [
                'favorite_genres' => [$id],
            ])->assertOk()
                ->assertJsonPath('data.favorite_genres', [$id]);

            $this->assertSame([$id], $user->fresh()->favorite_genres);

            $this->getJson(self::PROFILE)
                ->assertOk()
                ->assertJsonPath('data.favorite_genres', [$id]);
        }

        Http::assertNothingSent();
    }

    public function test_invalid_genres_reject_the_entire_update(): void
    {
        $user = User::factory()->create([
            'name' => 'Original',
            'favorite_genres' => [878],
        ]);

        $original = $user->fresh()->getRawOriginal();
        $this->actingAs($user, 'web');

        $cases = [
            [null, 'favorite_genres'],
            ['drama', 'favorite_genres'],
            [['drama'], 'favorite_genres.0'],
            [['878'], 'favorite_genres.0'],
            [[878, 878], 'favorite_genres.0'],
            [[true], 'favorite_genres.0'],
            [[28.5], 'favorite_genres.0'],
            [[0], 'favorite_genres.0'],
            [[999999], 'favorite_genres.0'],
            [[10759], 'favorite_genres.0'],
            [[28, 12, 16, 35, 80, 99], 'favorite_genres'],
            [['first' => 28], 'favorite_genres'],
        ];

        foreach ($cases as [$genres, $field]) {
            $this->patchJson(self::PROFILE, [
                'name' => 'Must not save',
                'favorite_genres' => $genres,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors($field);

            $this->assertSame($original, $user->fresh()->getRawOriginal());
        }
    }

    public function test_saved_preferences_change_final_ranking_and_clearing_disables_the_signal(): void
    {
        config([
            'services.tmdb.token' => null,
            'services.tmdb.base_url' => null,
        ]);

        Http::preventStrayRequests();

        $user = User::factory()->create();

        $mystery = Movie::factory()->create([
            'title' => 'Mystery preference fixture',
            'release_date' => '2020-01-01',
            'genres' => [['tmdb_id' => 9648, 'name' => 'Mystery']],
        ]);

        $family = Movie::factory()->create([
            'title' => 'Family preference fixture',
            'release_date' => '2020-01-01',
            'genres' => [['tmdb_id' => 10751, 'name' => 'Family']],
        ]);

        $this->actingAs($user, 'web');

        $this->patchJson(self::PROFILE, [
            'favorite_genres' => [9648],
        ])->assertOk();

        $first = $this->getJson('/api/v1/recommendations?limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.local_movie_id', (string) $mystery->id)
            ->assertJsonPath('data.0.recommendation.score', 82.22)
            ->assertJsonPath('data.0.recommendation.signals.genre.value', 1)
            ->assertJsonPath(
                'data.0.recommendation.reasons.0.code',
                'genre_preference',
            )
            ->assertJsonPath(
                'data.0.recommendation.reasons.0.params.genres',
                ['mystery'],
            );

        $this->assertGreaterThan(
            $first->json('data.1.recommendation.score'),
            $first->json('data.0.recommendation.score'),
        );

        $this->patchJson(self::PROFILE, [
            'favorite_genres' => [10751],
        ])->assertOk();

        $this->getJson('/api/v1/recommendations?limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.local_movie_id', (string) $family->id)
            ->assertJsonPath('data.0.recommendation.score', 82.22)
            ->assertJsonPath(
                'data.0.recommendation.reasons.0.params.genres',
                ['family'],
            );

        $this->patchJson(self::PROFILE, [
            'favorite_genres' => [],
        ])->assertOk();

        $cleared = $this->getJson('/api/v1/recommendations?limit=2')
            ->assertOk()
            ->assertJsonPath('meta.personalization', 'general');

        foreach ($cleared->json('data') as $card) {
            $this->assertNull(
                $card['recommendation']['signals']['genre']['value'],
            );
            $this->assertEquals(
                0,
                $card['recommendation']['signals']['genre']['weight'],
            );
            $this->assertEquals(60, $card['recommendation']['score']);
        }

        Http::assertNothingSent();
    }

    public function test_normalization_preserves_catalog_formats_and_adds_integer_preferences(): void
    {
        $actual = app(RecommendationGenreNormalizer::class)->normalize([
            878,
            ['tmdb_id' => 10751, 'name' => 'Localized label'],
            ['id' => 36, 'name' => 'History'],
            'Science Fiction',
            ['tmdb_id' => 999999, 'name' => 'Music'],
            999999,
            'unknown',
            false,
        ]);

        $this->assertSame(
            ['science-fiction', 'family', 'history', 'music'],
            $actual,
        );
    }

    public function test_migration_preserves_preferences_nulls_and_timestamps(): void
    {
        $legacy = User::factory()->create([
            'favorite_genres' => ['science-fiction', 'mystery'],
        ]);
        $numeric = User::factory()->create([
            'favorite_genres' => [10751, 10402],
        ]);
        $empty = User::factory()->create(['favorite_genres' => []]);
        $missing = User::factory()->create(['favorite_genres' => null]);

        $updatedAt = $legacy->getRawOriginal('updated_at');
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up();

        $this->assertSame([878, 9648], $legacy->fresh()->favorite_genres);
        $this->assertSame([10751, 10402], $numeric->fresh()->favorite_genres);
        $this->assertSame([], $empty->fresh()->favorite_genres);
        $this->assertNull($missing->fresh()->favorite_genres);
        $this->assertSame(
            $updatedAt,
            $legacy->fresh()->getRawOriginal('updated_at'),
        );

        $migration->down();

        $this->assertSame(
            ['science-fiction', 'mystery'],
            $legacy->fresh()->favorite_genres,
        );
        $this->assertSame(
            ['family', 'music'],
            $numeric->fresh()->favorite_genres,
        );

        $migration->up();

        $this->assertSame([878, 9648], $legacy->fresh()->favorite_genres);
    }

    public function test_invalid_stored_data_aborts_without_partial_conversion(): void
    {
        $valid = User::factory()->create([
            'favorite_genres' => ['drama'],
        ]);
        $invalid = User::factory()->create([
            'favorite_genres' => ['unrecognized-genre'],
        ]);

        $migration = require database_path(self::MIGRATION);

        try {
            $migration->up();
            $this->fail('Expected invalid stored preferences to abort migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                "user {$invalid->id}",
                $exception->getMessage(),
            );
        }

        $this->assertSame(['drama'], $valid->fresh()->favorite_genres);
        $this->assertSame(
            ['unrecognized-genre'],
            $invalid->fresh()->favorite_genres,
        );
    }
}