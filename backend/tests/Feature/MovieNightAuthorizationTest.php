<?php

namespace Tests\Feature;

use App\Models\MovieNight;
use App\Models\User;
use App\Services\MovieNightAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MovieNightAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('statusPermissions')]
    public function test_organizer_permissions_follow_status(
        string $status,
        bool $editable,
        bool $cancellable,
        bool $voting,
        bool $messaging,
    ): void {
        $night = $this->nightWithStatus($status);
        $gate = Gate::forUser($night->organizer);

        $this->assertTrue($gate->allows('view', $night));
        $this->assertSame($editable, $gate->allows('update', $night));
        $this->assertSame($cancellable, $gate->allows('cancel', $night));
        $this->assertSame($voting, $gate->allows('vote', $night));
        $this->assertSame($messaging, $gate->allows('sendMessage', $night));
    }

    public static function statusPermissions(): array
    {
        return [
            'draft' => ['draft', true, true, false, false],
            'scheduled' => ['scheduled', true, true, true, true],
            'finalized' => ['finalized', false, true, false, true],
            'cancelled' => ['cancelled', false, false, false, false],
        ];
    }

    public function test_strangers_admins_and_guests_have_no_ownership_bypass(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        foreach ([$stranger, $admin, null] as $actor) {
            foreach ([
                'view',
                'update',
                'cancel',
                'vote',
                'sendMessage',
            ] as $ability) {
                $this->assertTrue(
                    Gate::forUser($actor)->denies($ability, $night),
                    "Unexpected permission for {$ability}.",
                );
            }
        }
    }

    public function test_organizer_can_update_and_cancel_through_service(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $organizer = $night->organizer;
        $service = app(MovieNightAccessService::class);

        $updated = $service->update($organizer, $night, [
            'title' => '  Updated movie night  ',
            'location' => '  Campus room 2  ',
            'meeting_url' => 'https://meet.example.test/movie-night',
        ]);

        $this->assertSame('Updated movie night', $updated->title);
        $this->assertSame('Campus room 2', $updated->location);

        $cancelled = $service->cancel($organizer, $updated);

        $this->assertSame(
            MovieNight::STATUS_CANCELLED,
            $cancelled->status,
        );
        $this->assertDatabaseHas('movie_nights', [
            'id' => $night->id,
            'organizer_id' => $organizer->id,
            'status' => MovieNight::STATUS_CANCELLED,
        ]);
    }

    #[DataProvider('ownerMutations')]
    public function test_non_organizer_cannot_update_or_cancel(string $method): void
    {
        $night = MovieNight::factory()->create();
        $stranger = User::factory()->create();
        // Compare database-loaded snapshots with consistent attribute ordering.
        $original = $night->refresh()->getRawOriginal();
        $service = app(MovieNightAccessService::class);

        try {
            if ($method === 'update') {
                $service->update($stranger, $night, [
                    'title' => 'Unauthorized change',
                ]);
            } else {
                $service->cancel($stranger, $night);
            }

            $this->fail('Expected organizer authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertSame(
                $original,
                $night->refresh()->getRawOriginal(),
            );
        }
    }

    public static function ownerMutations(): array
    {
        return [
            'update' => ['update'],
            'cancel' => ['cancel'],
        ];
    }

    public function test_update_rejects_protected_fields_without_partial_save(): void
    {
        $night = MovieNight::factory()->create();
        $organizer = $night->organizer;
        // Compare database-loaded snapshots with consistent attribute ordering.
        $original = $night->refresh()->getRawOriginal();
        $service = app(MovieNightAccessService::class);

        foreach ([
            'organizer_id' => 999999,
            'status' => MovieNight::STATUS_SCHEDULED,
            'winning_movie_id' => 999999,
        ] as $field => $value) {
            try {
                $service->update($organizer, $night, [
                    'title' => 'Must not be partially saved',
                    $field => $value,
                ]);

                $this->fail("Expected rejection of {$field}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());

                $this->assertSame(
                    $original,
                    $night->refresh()->getRawOriginal(),
                );
            }
        }
    }

    #[DataProvider('participationGuards')]
    public function test_cancelled_night_rejects_stale_write_callbacks(
        string $method,
    ): void {
        $night = MovieNight::factory()->scheduled()->create();
        $organizer = $night->organizer;
        $stale = $night->fresh();
        $service = app(MovieNightAccessService::class);
        $called = false;

        $service->cancel($organizer, $night);

        // The caller still holds an outdated scheduled instance.
        $this->assertSame(MovieNight::STATUS_SCHEDULED, $stale->status);

        try {
            $service->{$method}(
                $organizer,
                $stale,
                function (MovieNight $current) use (&$called): void {
                    $called = true;
                },
            );

            $this->fail('Expected cancelled-night authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertFalse($called);
            $this->assertSame(
                MovieNight::STATUS_CANCELLED,
                $night->refresh()->status,
            );
        }
    }

    public static function participationGuards(): array
    {
        return [
            'vote' => ['forVote'],
            'message' => ['forMessage'],
        ];
    }

    public function test_stale_instance_cannot_edit_after_cancellation(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $organizer = $night->organizer;
        $stale = $night->fresh();
        $service = app(MovieNightAccessService::class);

        $service->cancel($organizer, $night);

        $this->expectException(AuthorizationException::class);

        $service->update($organizer, $stale, [
            'title' => 'Must not change a cancelled night',
        ]);
    }

    public function test_allowed_callback_result_is_returned(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $service = app(MovieNightAccessService::class);

        $result = $service->forVote(
            $night->organizer,
            $night,
            fn (MovieNight $current): string => $current->id,
        );

        $this->assertSame($night->id, $result);
    }

    public function test_failed_callback_rolls_back_its_database_work(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $originalTitle = $night->title;
        $service = app(MovieNightAccessService::class);

        try {
            $service->forMessage(
                $night->organizer,
                $night,
                function (MovieNight $current): void {
                    $current->title = 'Must roll back';
                    $current->save();

                    throw new RuntimeException('Simulated write failure');
                },
            );

            $this->fail('Expected callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated write failure',
                $exception->getMessage(),
            );
            $this->assertSame(
                $originalTitle,
                $night->refresh()->title,
            );
        }
    }

    private function nightWithStatus(string $status): MovieNight
    {
        return $status === MovieNight::STATUS_FINALIZED
            ? MovieNight::factory()->finalized()->create()
            : MovieNight::factory()->create(['status' => $status]);
    }
}