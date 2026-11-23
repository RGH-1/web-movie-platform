<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/recommendations';

    private const DISCOVER = 'https://tmdb.test/3/discover/movie*';

    private const FORECAST = 'https://api.open-meteo.com/v1/forecast';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tmdb.base_url' => 'https://tmdb.test/3',
            'services.tmdb.token' => 'test-token',
            'services.weather.weather_url' => self::FORECAST,
        ]);
        Http::preventStrayRequests();
    }

    public function test_profiles_with_different_watched_histories_rank_the_same_candidates_differently(): void
    {
        Http::fake([self::DISCOVER => Http::response(['results' => []])]);
        $mystery = $this->movie('Mystery candidate', 9648, 'Mystery');
        $adventure = $this->movie('Adventure candidate', 12, 'Adventure');
        $watchedMystery = $this->movie('Watched mystery', 9648, 'Mystery');
        $watchedAdventure = $this->movie('Watched adventure', 12, 'Adventure');
        $mysteryFan = User::factory()->create(['favorite_genres' => []]);
        $adventureFan = User::factory()->create(['favorite_genres' => []]);
        $mysteryFan->movieStatuses()->create(['movie_id' => $watchedMystery->id, 'watched_at' => now()]);
        $adventureFan->movieStatuses()->create(['movie_id' => $watchedAdventure->id, 'watched_at' => now()]);
        $this->addHiddenReview($mysteryFan, $watchedAdventure);
        $this->addHiddenReview($adventureFan, $watchedMystery);

        $this->actingAs($mysteryFan, 'web')->getJson(self::ENDPOINT.'?limit=2')
            ->assertOk()
            ->assertJsonPath('meta.personalization', 'personalized')
            ->assertJsonPath('data.0.local_movie_id', (string) $mystery->id)
            ->assertJsonPath('data.1.local_movie_id', (string) $adventure->id)
            ->assertJsonPath('data.0.recommendation.signals.history.value', 1)
            ->assertJsonPath('data.1.recommendation.signals.history.value', 0);

        Auth::forgetGuards();
        $this->actingAs($adventureFan, 'web')->getJson(self::ENDPOINT.'?limit=2')
            ->assertOk()
            ->assertJsonPath('meta.personalization', 'personalized')
            ->assertJsonPath('data.0.local_movie_id', (string) $adventure->id)
            ->assertJsonPath('data.1.local_movie_id', (string) $mystery->id)
            ->assertJsonPath('data.0.recommendation.signals.history.value', 1)
            ->assertJsonPath('data.1.recommendation.signals.history.value', 0);
    }

    public function test_rain_and_clear_weather_reverse_ranking_for_the_same_user(): void
    {
        Http::fake([
            self::DISCOVER => Http::response(['results' => []]),
            self::FORECAST.'*' => Http::sequence()
                ->push($this->forecast(61))
                ->push($this->forecast(0)),
        ]);
        $mystery = $this->movie('Mystery candidate', 9648, 'Mystery');
        $adventure = $this->movie('Adventure candidate', 12, 'Adventure');
        $this->actingAs(User::factory()->create(['favorite_genres' => []]), 'web');

        $this->getJson(self::ENDPOINT.'?latitude=33.89&longitude=35.50')
            ->assertOk()
            ->assertJsonPath('meta.weather.condition', 'rainy')
            ->assertJsonPath('data.0.local_movie_id', (string) $mystery->id)
            ->assertJsonPath('data.1.local_movie_id', (string) $adventure->id)
            ->assertJsonPath('data.0.recommendation.reasons.0.code', 'weather_match');

        // A different coordinate pair avoids replaying the cached forecast.
        $this->getJson(self::ENDPOINT.'?latitude=33.90&longitude=35.50')
            ->assertOk()
            ->assertJsonPath('meta.weather.condition', 'clear')
            ->assertJsonPath('data.0.local_movie_id', (string) $adventure->id)
            ->assertJsonPath('data.1.local_movie_id', (string) $mystery->id)
            ->assertJsonPath('data.0.recommendation.reasons.0.code', 'weather_match');
    }

    public function test_repeated_provider_candidates_and_local_mappings_cannot_duplicate_or_restore_excluded_movies(): void
    {
        $watched = Movie::factory()->tmdb(901)->create();
        $reviewed = Movie::factory()->tmdb(902)->create();
        $watchlist = Movie::factory()->tmdb(903)->create(['title' => 'Local watchlist metadata']);
        $user = User::factory()->create(['favorite_genres' => [9648]]);
        $user->movieStatuses()->create(['movie_id' => $watched->id, 'watched_at' => now()]);
        $user->movieStatuses()->create(['movie_id' => $watchlist->id, 'in_watchlist' => true]);
        $this->addHiddenReview($user, $reviewed);

        $cards = array_map(fn (int $id): array => [
            'id' => $id,
            'title' => 'Provider title '.$id,
            'genre_ids' => [9648],
            'vote_average' => 8,
        ], [901, 902, 903, 904, 904]);
        Http::fake([
            self::DISCOVER => Http::response(['results' => $cards]),
            'https://tmdb.test/3/movie/904*' => Http::response([
                'id' => 904, 'title' => 'Provider-only movie',
                'genres' => [['id' => 9648, 'name' => 'Mystery']],
            ]),
        ]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.partial', false)
            ->assertJsonPath('meta.candidate_count', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['local_movie_id' => (string) $watchlist->id, 'title' => $watchlist->title]);

        $this->assertEqualsCanonicalizing([903, 904], array_column($response->json('data'), 'tmdb_id'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => (string) ($request->data()['with_genres'] ?? '') === '9648');
    }

    public function test_new_user_without_preferences_or_history_gets_quality_ranked_general_results(): void
    {
        Http::fake([self::DISCOVER => Http::response(['results' => []])]);
        $higherQuality = Movie::factory()->tmdb(911)->create(['vote_average' => 9]);
        $lowerQuality = Movie::factory()->tmdb(912)->create(['vote_average' => 6]);
        $user = User::factory()->create(['favorite_genres' => []]);

        $response = $this->actingAs($user, 'web')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.personalization', 'general')
            ->assertJsonPath('meta.partial', false)
            ->assertJsonPath('meta.weather.status', 'not_requested')
            ->assertJsonPath('data.0.local_movie_id', (string) $higherQuality->id)
            ->assertJsonPath('data.1.local_movie_id', (string) $lowerQuality->id)
            ->assertJsonPath('data.0.recommendation.signals.quality.weight', 1);

        foreach (['genre', 'history', 'people', 'similarity', 'weather'] as $signal) {
            $response->assertJsonPath("data.0.recommendation.signals.{$signal}.evidence", 'unavailable')
                ->assertJsonPath("data.0.recommendation.signals.{$signal}.value", null);
        }
        Http::assertSentCount(1);
    }

    public function test_provider_failures_return_local_results_or_explain_why_the_list_is_empty(): void
    {
        Http::fake([
            self::DISCOVER => Http::response([], 503),
            self::FORECAST.'*' => Http::failedConnection('Weather request timed out'),
        ]);
        $local = Movie::factory()->create();
        $this->actingAs(User::factory()->create(['favorite_genres' => []]), 'web');
        $url = self::ENDPOINT.'?latitude=33.89&longitude=35.50';

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.partial', true)
            ->assertJsonPath('meta.weather.status', 'unavailable')
            ->assertJsonPath('meta.warnings', ['WEATHER_UNAVAILABLE', 'TMDB_UNAVAILABLE'])
            ->assertJsonPath('meta.empty_reason', null)
            ->assertJsonPath('data.0.local_movie_id', (string) $local->id)
            ->assertJsonPath('data.0.recommendation.signals.weather.evidence', 'unavailable');

        $local->delete();

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.partial', true)
            ->assertJsonPath('meta.empty_reason', 'sources_unavailable')
            ->assertJsonPath('data', []);
    }

    private function addHiddenReview(User $user, Movie $movie): void
    {
        $user->reviews()->make(['rating' => 2])->movie()->associate($movie)
            ->forceFill(['moderation_status' => 'hidden'])->save();
    }

    private function movie(string $title, int $genreId, string $genre): Movie
    {
        return Movie::factory()->create([
            'title' => $title,
            'genres' => [['tmdb_id' => $genreId, 'name' => $genre]],
        ]);
    }

    private function forecast(int $weatherCode): array
    {
        return ['current' => [
            'weather_code' => $weatherCode,
            'temperature_2m' => 20,
            'time' => now()->utc()->toISOString(),
        ]];
    }
}
