<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\User;
use App\Models\UserMovieStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminMovieCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_URL = '/api/v1/admin/movies';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guests_and_non_administrators_cannot_access_any_admin_movie_operation(): void
    {
        $movie = Movie::factory()->create();
        $urls = [
            ['getJson', self::ADMIN_URL], ['getJson', self::ADMIN_URL.'/'.$movie->id],
            ['postJson', self::ADMIN_URL], ['patchJson', self::ADMIN_URL.'/'.$movie->id], ['deleteJson', self::ADMIN_URL.'/'.$movie->id],
        ];
        foreach ($urls as [$method, $url]) {
            $this->{$method}($url)->assertUnauthorized();
        }

        $this->signIn(User::factory()->create());
        foreach ($urls as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
    }

    public function test_administrator_creates_local_movie_and_it_is_available_to_catalog_users(): void
    {
        $this->signIn($this->admin());
        $response = $this->postJson(self::ADMIN_URL, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.source', 'local')
            ->assertJsonPath('data.tmdb_id', null)
            ->assertJsonPath('data.vote_average', null)
            ->assertJsonPath('data.metadata_overrides', []);
        $id = $response->json('data.local_movie_id');

        $this->assertDatabaseHas('movies', ['id' => $id, 'source' => 'local', 'tmdb_id' => null]);
        $this->signIn(User::factory()->create());
        $this->getJson('/api/v1/catalog/movies?source=local&search=Lebanese')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.local_movie_id', $id);
        $this->getJson('/api/v1/catalog/movies/'.$id)
            ->assertOk()
            ->assertJsonPath('data.title', 'A Lebanese Short Film');
        Http::assertNothingSent();
    }

    public function test_validation_rejects_invalid_and_protected_fields_without_mutating_movies(): void
    {
        $movie = Movie::factory()->create(['title' => 'Original']);
        $original = $movie->fresh()->getRawOriginal();
        $this->signIn($this->admin());

        foreach ([
            ['title' => '   '],
            ['release_date' => '2026-02-30'],
            ['runtime_minutes' => 0],
            ['genres' => [['tmdb_id' => 1, 'name' => 'Drama', 'extra' => 'no']]],
            ['cast' => [['tmdb_person_id' => null, 'name' => 'Actor', 'character' => null, 'profile_url' => 'http://unsafe.test/a.jpg']]],
            ['trailer' => ['site' => 'Vimeo', 'key' => 'bad']],
            ['tmdb_id' => 77, 'source' => 'tmdb', 'deactivated_at' => now()->toISOString()],
        ] as $payload) {
            $this->patchJson(self::ADMIN_URL.'/'.$movie->id, $payload)->assertUnprocessable();
            $this->assertSame($original, $movie->refresh()->getRawOriginal());
        }
    }

    public function test_local_updates_preserve_omitted_metadata_and_tmdb_updates_preserve_identity_and_overrides(): void
    {
        $local = Movie::factory()->create(['title' => 'Before', 'overview' => 'Keep this']);
        $tmdb = Movie::factory()->tmdb(600)->create([
            'title' => 'TMDB before',
            'overview' => 'Provider overview',
            'metadata_overrides' => ['director'],
        ]);
        $this->signIn($this->admin());

        $this->patchJson(self::ADMIN_URL.'/'.$local->id, ['title' => ' After '])
            ->assertOk()
            ->assertJsonPath('data.title', 'After');
        $this->assertSame('Keep this', $local->refresh()->overview);

        $this->patchJson(self::ADMIN_URL.'/'.$tmdb->id, ['overview' => null, 'genres' => []])
            ->assertOk()
            ->assertJsonPath('data.overview', null)
            ->assertJsonPath('data.genres', []);
        $tmdb->refresh();
        $this->assertSame(600, $tmdb->tmdb_id);
        $this->assertSame('tmdb', $tmdb->source);
        $this->assertSame(['director', 'overview', 'genres'], $tmdb->metadata_overrides);
    }

    public function test_deactivation_is_idempotent_and_preserves_historical_relationships(): void
    {
        $movie = Movie::factory()->create();
        $user = User::factory()->create();
        $review = $user->reviews()->make(['rating' => 5]);
        $review->movie()->associate($movie);
        $review->save();
        $status = UserMovieStatus::query()->create(['user_id' => $user->id, 'movie_id' => $movie->id, 'in_watchlist' => true, 'watched_at' => now()]);
        $list = $user->movieLists()->create(['name' => 'History']);
        $list->movies()->attach($movie->id);
        $this->signIn($this->admin());

        $first = $this->deleteJson(self::ADMIN_URL.'/'.$movie->id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $timestamp = $first->json('data.deactivated_at');
        $this->deleteJson(self::ADMIN_URL.'/'.$movie->id)
            ->assertOk()
            ->assertJsonPath('data.deactivated_at', $timestamp);

        $this->assertDatabaseHas('movies', ['id' => $movie->id]);
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('user_movie_statuses', ['id' => $status->id]);
        $this->assertDatabaseHas('movie_list_items', ['movie_list_id' => $list->id, 'movie_id' => $movie->id]);
        $this->getJson(self::ADMIN_URL.'/'.$movie->id)->assertOk()->assertJsonPath('data.is_active', false);

        $this->signIn($user);
        $this->getJson('/api/v1/catalog/movies?search='.$movie->title)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/catalog/movies/'.$movie->id)->assertNotFound();
        $this->signIn(User::factory()->create());
        $this->patchJson('/api/v1/me/movies/'.$movie->id.'/status', ['in_watchlist' => true])->assertStatus(409);
    }

    public function test_admin_listing_filters_searches_paginates_and_includes_deactivated_records(): void
    {
        $local = Movie::factory()->create(['title' => 'Alpha local']);
        $tmdb = Movie::factory()->tmdb(700)->create(['title' => 'Bravo TMDB']);
        $inactive = Movie::factory()->create(['title' => 'Charlie inactive', 'deactivated_at' => now()]);
        $this->signIn($this->admin());

        $this->getJson(self::ADMIN_URL.'?source=local&status=active&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.local_movie_id', (string) $local->id);
        $this->getJson(self::ADMIN_URL.'?source=tmdb')->assertOk()->assertJsonPath('data.0.tmdb_id', 700);
        $this->getJson(self::ADMIN_URL.'?status=deactivated&search=Charlie')
            ->assertOk()
            ->assertJsonPath('data.0.local_movie_id', (string) $inactive->id)
            ->assertJsonPath('data.0.is_active', false);
        foreach (['source=bad', 'status=removed', 'per_page=51', 'page=0'] as $query) {
            $this->getJson(self::ADMIN_URL.'?'.$query)->assertUnprocessable();
        }
        $this->assertNotNull($tmdb->id);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function signIn(User $user): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => ' A Lebanese Short Film ',
            'overview' => 'An independent short film.',
            'release_date' => '2026-05-15',
            'runtime_minutes' => 28,
            'genres' => [['tmdb_id' => 18, 'name' => 'Drama']],
            'poster_url' => null,
            'backdrop_url' => null,
            'director' => 'Example Director',
            'cast' => [],
            'trailer' => null,
        ];
    }
}
