<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\MovieNight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MovieNightSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_relationships_and_utc_start_are_preserved(): void
    {
        $organizer = User::factory()->create();

        $night = $organizer->organizedMovieNights()->create([
            'title' => '  Weekend movies  ',
            'starts_at' => '2026-11-28T20:00:00+02:00',
            'timezone' => 'Asia/Beirut',
        ])->refresh();

        $this->assertSame('Weekend movies', $night->title);
        $this->assertSame(MovieNight::STATUS_DRAFT, $night->status);
        $this->assertNull($night->description);
        $this->assertNull($night->location);
        $this->assertNull($night->meeting_url);
        $this->assertNull($night->winning_movie_id);
        $this->assertNull($night->winningMovie);

        $this->assertIsString($night->id);
        $this->assertIsString($night->organizer_id);
        $this->assertInstanceOf(CarbonImmutable::class, $night->starts_at);
        $this->assertSame(
            '2026-11-28 18:00:00',
            $night->getRawOriginal('starts_at'),
        );
        $this->assertSame('Asia/Beirut', $night->timezone);

        $this->assertTrue($night->organizer->is($organizer));
        $this->assertTrue(
            $organizer->organizedMovieNights()->sole()->is($night)
        );

        $night->update(['timezone' => 'Europe/Paris']);

        $this->assertSame(
            '2026-11-28 18:00:00',
            $night->refresh()->getRawOriginal('starts_at'),
        );
    }

    public function test_database_defaults_apply_without_eloquent(): void
    {
        $row = $this->rawRow();
        unset($row['status'], $row['timezone']);

        $id = DB::table('movie_nights')->insertGetId($row);
        $night = MovieNight::findOrFail($id);

        $this->assertSame(MovieNight::STATUS_DRAFT, $night->status);
        $this->assertSame('UTC', $night->timezone);
        $this->assertNull($night->winning_movie_id);
    }

    public function test_winner_uses_canonical_identity_and_survives_deactivation(): void
    {
        $local = Movie::factory()->create();
        $providerMovie = Movie::factory()->tmdb((int) $local->id)->create();

        $night = MovieNight::factory()->finalized()->create([
            'winning_movie_id' => $local->id,
        ]);

        $this->assertTrue($night->winningMovie->is($local));
        $this->assertFalse($night->winningMovie->is($providerMovie));
        $this->assertTrue(
            $local->winningMovieNights()->sole()->is($night)
        );

        $local->update(['deactivated_at' => now()]);

        $this->assertSame(
            $local->id,
            $night->refresh()->winning_movie_id,
        );
        $this->assertTrue($night->winningMovie->is($local));
    }

    #[DataProvider('validStatuses')]
    public function test_supported_statuses_can_be_stored(string $status): void
    {
        $factory = $status === MovieNight::STATUS_FINALIZED
            ? MovieNight::factory()->finalized()
            : MovieNight::factory();

        $night = $factory->create(['status' => $status]);

        $this->assertSame($status, $night->refresh()->status);
    }

    public static function validStatuses(): array
    {
        return array_map(
            static fn (string $status): array => [$status],
            MovieNight::STATUSES,
        );
    }

    #[DataProvider('invalidAttributes')]
    public function test_invalid_model_data_is_rejected(
        array $attributes,
        string $field,
    ): void {
        try {
            MovieNight::factory()->create($attributes);
            $this->fail('Expected validation to reject the movie night.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertDatabaseCount('movie_nights', 0);
    }

    public static function invalidAttributes(): array
    {
        return [
            'unknown status' => [['status' => 'published'], 'status'],
            'missing status' => [['status' => null], 'status'],
            'blank title' => [['title' => '   '], 'title'],
            'long title' => [['title' => str_repeat('x', 256)], 'title'],
            'long description' => [
                ['description' => str_repeat('x', 2001)],
                'description',
            ],
            'invalid timezone' => [
                ['timezone' => 'Not/A_Zone'],
                'timezone',
            ],
            'fixed offset is not a named zone' => [
                ['timezone' => '+02:00'],
                'timezone',
            ],
            'missing start' => [['starts_at' => null], 'starts_at'],
            'ambiguous start without offset' => [
                ['starts_at' => '2026-11-28T20:00:00'],
                'starts_at',
            ],
            'invalid calendar date' => [
                ['starts_at' => '2026-02-30T20:00:00Z'],
                'starts_at',
            ],
            'unsafe meeting protocol' => [
                ['meeting_url' => 'javascript:alert(1)'],
                'meeting_url',
            ],
            'finalized without winner' => [
                ['status' => MovieNight::STATUS_FINALIZED],
                'winning_movie_id',
            ],
        ];
    }

    public function test_protected_fields_cannot_be_mass_assigned(): void
    {
        $organizer = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::factory()->create();

        $night = new MovieNight([
            'title' => 'Protected ownership',
            'starts_at' => now()->addDay(),
            'organizer_id' => $other->id,
            'status' => MovieNight::STATUS_FINALIZED,
            'winning_movie_id' => $movie->id,
        ]);

        $this->assertNull($night->organizer_id);
        $this->assertNull($night->winning_movie_id);
        $this->assertSame(MovieNight::STATUS_DRAFT, $night->status);

        $night->organizer()->associate($organizer);
        $night->save();

        $this->assertSame(
            (string) $organizer->id,
            $night->refresh()->organizer_id,
        );
    }

    public function test_lifecycle_preserves_winner_and_cancellation_is_terminal(): void
    {
        $night = MovieNight::factory()->create();
        $winner = Movie::factory()->create();

        $night->status = MovieNight::STATUS_SCHEDULED;
        $night->save();

        $night->forceFill([
            'status' => MovieNight::STATUS_FINALIZED,
            'winning_movie_id' => $winner->id,
        ])->save();

        $night->status = MovieNight::STATUS_CANCELLED;
        $night->save();

        $this->assertSame(
            $winner->id,
            $night->refresh()->winning_movie_id,
        );

        $this->expectException(ValidationException::class);

        $night->status = MovieNight::STATUS_DRAFT;
        $night->save();
    }

    public function test_organizer_cannot_be_reassigned(): void
    {
        $night = MovieNight::factory()->create();
        $other = User::factory()->create();

        $this->expectException(ValidationException::class);

        $night->organizer()->associate($other);
        $night->save();
    }

    public function test_draft_cannot_skip_directly_to_finalized(): void
    {
        $night = MovieNight::factory()->create();
        $winner = Movie::factory()->create();

        $this->expectException(ValidationException::class);

        $night->forceFill([
            'status' => MovieNight::STATUS_FINALIZED,
            'winning_movie_id' => $winner->id,
        ])->save();
    }

    public function test_existing_winner_cannot_be_replaced_after_finalization(): void
    {
        $night = MovieNight::factory()->finalized()->create();
        $replacement = Movie::factory()->create();

        $this->expectException(ValidationException::class);

        $night->winningMovie()->associate($replacement);
        $night->save();
    }

    public function test_database_rejects_unknown_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('movie_nights')->insert(
            $this->rawRow(['status' => 'unknown'])
        );
    }

    #[DataProvider('foreignKeys')]
    public function test_database_rejects_missing_references(string $field): void
    {
        $row = $this->rawRow([$field => 999999]);

        $this->expectException(QueryException::class);

        DB::table('movie_nights')->insert($row);
    }

    public static function foreignKeys(): array
    {
        return [
            'organizer' => ['organizer_id'],
            'winner' => ['winning_movie_id'],
        ];
    }

    public function test_referenced_organizer_cannot_be_deleted(): void
    {
        $night = MovieNight::factory()->create();
        $organizer = $night->organizer;

        $this->expectException(QueryException::class);

        $organizer->delete();
    }

    public function test_referenced_winning_movie_cannot_be_deleted(): void
    {
        $night = MovieNight::factory()->finalized()->create();
        $winner = $night->winningMovie;

        $this->expectException(QueryException::class);

        $winner->delete();
    }

    private function rawRow(array $changes = []): array
    {
        return array_replace([
            'organizer_id' => User::factory()->create()->id,
            'title' => 'Database fixture',
            'starts_at' => '2026-11-28 18:00:00',
            'timezone' => 'UTC',
            'status' => MovieNight::STATUS_DRAFT,
            'winning_movie_id' => null,
        ], $changes);
    }
}