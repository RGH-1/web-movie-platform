<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_validation_and_general_local_recommendation_contract(): void
    {
        $this->getJson('/api/v1/recommendations')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/recommendations?user_id=2')->assertUnprocessable();
        $this->getJson('/api/v1/recommendations?latitude=10')->assertUnprocessable();
        Movie::factory()->create(['title' => 'Eligible', 'release_date' => '2020-01-01']);
        Http::preventStrayRequests();
        $this->getJson('/api/v1/recommendations')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.requested_limit', 12)->assertJsonPath('meta.personalization', 'general')
            ->assertJsonPath('data.0.title', 'Eligible')->assertJsonStructure(['data' => [['recommendation' => ['score', 'signals' => ['genre', 'quality', 'history', 'people', 'similarity', 'weather'], 'reasons']]], 'meta' => ['weather']]);
        Http::assertNothingSent();
    }

    public function test_city_lookup_requires_authentication_and_valid_input(): void
    {
        Http::preventStrayRequests();
        $this->getJson('/api/v1/recommendations/city?city=Beirut')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/recommendations/city')->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->getJson('/api/v1/recommendations/city?city[]=Beirut')->assertUnprocessable()->assertJsonValidationErrors('city');
        $this->getJson('/api/v1/recommendations/city?city='.str_repeat('a', 121))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_city_lookup_reuses_weather_geocoding_and_returns_null_on_failure(): void
    {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::sequence()
            ->push(['results' => [
                ['name' => 'Beirut', 'country' => 'Lebanon', 'latitude' => 33.8938, 'longitude' => 35.5018],
            ]])
            ->pushStatus(503)]);
        $this->actingAs(User::factory()->create(), 'web');

        $this->getJson('/api/v1/recommendations/city?city=Beirut%2C%20Lebanon')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => ['latitude' => 33.89, 'longitude' => 35.5]]);
        $this->getJson('/api/v1/recommendations/city?city=Paris')
            ->assertOk()->assertExactJson(['data' => null]);
        Http::assertSentCount(2);
    }

    public function test_watched_and_hidden_reviewed_movies_are_excluded_while_watchlist_only_is_eligible(): void
    {
        $user = User::factory()->create();
        $watched = Movie::factory()->create();
        $reviewed = Movie::factory()->create();
        $watchlist = Movie::factory()->create(['title' => 'Watchlist only']);
        $user->movieStatuses()->create(['movie_id' => $watched->id, 'watched_at' => now()]);
        $user->movieStatuses()->create(['movie_id' => $watchlist->id, 'in_watchlist' => true]);
        $review = $user->reviews()->make(['rating' => 2]);
        $review->movie()->associate($reviewed);
        $review->forceFill(['moderation_status' => 'hidden'])->save();
        $this->actingAs($user, 'web')->getJson('/api/v1/recommendations?limit=20')->assertOk()
            ->assertJsonMissing(['title' => $watched->title])
            ->assertJsonMissing(['title' => $reviewed->title])
            ->assertJsonFragment(['title' => 'Watchlist only']);
    }

    public function test_provider_card_is_enriched_once_and_the_warm_cache_makes_no_additional_requests(): void
    {
        Cache::flush();
        RateLimiter::clear('recommendations:provider:tmdb');
        config(['services.tmdb.base_url' => 'https://tmdb.test/3', 'services.tmdb.token' => 'test-token']);
        Http::fake([
            'https://tmdb.test/3/discover/movie*' => Http::response(['results' => [[
                'id' => 9001, 'title' => 'Provider Candidate', 'genre_ids' => [9648], 'vote_average' => 7.5, 'vote_count' => 4,
            ]]]),
            'https://tmdb.test/3/movie/9001*' => Http::response([
                'id' => 9001, 'title' => 'Provider Candidate', 'genres' => [['id' => 9648, 'name' => 'Mystery']], 'runtime' => 101,
                'vote_average' => 7.5, 'vote_count' => 4,
                'credits' => ['cast' => [['id' => 15, 'name' => 'Actor', 'order' => 0]], 'crew' => [['job' => 'Director', 'name' => 'Director']]],
            ]),
        ]);
        $user = User::factory()->create();

        $first = $this->actingAs($user, 'web')->getJson('/api/v1/recommendations?limit=1')->assertOk()
            ->assertJsonPath('data.0.tmdb_id', 9001)->assertJsonPath('data.0.local_movie_id', null);
        $this->assertSame(2, count(Http::recorded()));
        $this->actingAs($user, 'web')->getJson('/api/v1/recommendations?limit=1')->assertOk();
        $this->assertSame(2, count(Http::recorded()));
        $this->assertSame('tmdb', $first->json('data.0.source'));
    }

    public function test_local_genre_and_quality_buckets_keep_older_high_value_movies_when_catalog_exceeds_two_hundred(): void
    {
        config(['services.tmdb.base_url' => null, 'services.tmdb.token' => null]);
        $user = User::factory()->create(['favorite_genres' => ['mystery']]);
        $genreMovie = Movie::factory()->create(['title' => 'Genre bucket', 'genres' => [['tmdb_id' => 9648, 'name' => 'Mystery']]]);
        $qualityMovie = Movie::factory()->tmdb(990001)->create(['title' => 'Quality bucket', 'vote_average' => 10]);
        Movie::factory()->count(205)->create();
        $reviewer = User::factory()->create();
        $review = new Review(['rating' => 5]);
        $review->user()->associate($reviewer);
        $review->movie()->associate($qualityMovie);
        $review->save();

        $response = $this->actingAs($user, 'web')->getJson('/api/v1/recommendations?limit=20')->assertOk()
            ->assertJsonPath('meta.candidate_count', 200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains($genreMovie->title));
        $this->assertTrue($titles->contains($qualityMovie->title));
    }

    public function test_authenticated_user_is_throttled_after_ten_recommendation_requests(): void
    {
        $user = User::factory()->create();
        for ($request = 1; $request <= 10; $request++) {
            $this->actingAs($user, 'web')->getJson('/api/v1/recommendations')->assertOk();
        }
        $this->actingAs($user, 'web')->getJson('/api/v1/recommendations')
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }
}
