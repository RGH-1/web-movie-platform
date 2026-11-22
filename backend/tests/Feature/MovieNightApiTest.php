<?php

namespace Tests\Feature;

use App\Models\MovieNight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MovieNightApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/movie-nights';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-22T08:00:00Z'));
        Http::preventStrayRequests();
    }

    #[DataProvider('routes')]
    public function test_all_routes_require_authentication(
        string $method,
        string $path,
    ): void {
        $night = MovieNight::factory()->create();

        $this->json(
            $method,
            str_replace('{id}', $night->id, $path),
            [],
        )->assertUnauthorized();
    }

    public static function routes(): array
    {
        return [
            ['GET', self::BASE],
            ['POST', self::BASE],
            ['GET', self::BASE.'/{id}'],
            ['PATCH', self::BASE.'/{id}'],
            ['POST', self::BASE.'/{id}/cancel'],
        ];
    }

    public function test_incomplete_profile_cannot_create_or_list(): void
    {
        $this->signIn(User::factory()->create(['name' => ' ']));

        $this->getJson(self::BASE)
            ->assertForbidden()
            ->assertJsonPath('code', 'PROFILE_INCOMPLETE');

        $this->postJson(self::BASE, $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('movie_nights', 0);
    }

    public function test_create_assigns_organizer_and_serializes_utc_safely(): void
    {
        $organizer = User::factory()->create();
        $this->signIn($organizer);

        $response = $this->postJson(self::BASE, $this->payload([
            'title' => '  Weekend movies  ',
            'starts_at' => '2026-11-29T20:00:00+02:00',
            'timezone' => 'Asia/Beirut',
            'description' => '   ',
        ]))
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.title', 'Weekend movies')
            ->assertJsonPath('data.organizer.id', (string) $organizer->id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath(
                'data.starts_at',
                '2026-11-29T18:00:00.000000Z',
            )
            ->assertJsonPath('data.timezone', 'Asia/Beirut')
            ->assertJsonPath('data.viewer_role', 'organizer')
            ->assertJsonPath('data.permissions.update', true)
            ->assertJsonPath('data.summaries.participants.count', 0)
            ->assertJsonPath('data.summaries.invitations', null)
            ->assertJsonPath('data.summaries.shortlist', null)
            ->assertJsonMissingPath('data.organizer.email')
            ->assertJsonMissingPath('data.organizer.password');

        $this->assertIsString($response->json('data.id'));

        $this->assertDatabaseHas('movie_nights', [
            'id' => $response->json('data.id'),
            'organizer_id' => $organizer->id,
            'starts_at' => '2026-11-29 18:00:00',
            'status' => 'draft',
        ]);

        Http::assertNothingSent();
    }

    public function test_listing_is_scoped_paginated_and_filterable(): void
    {
        $viewer = User::factory()->create();

        $ownDraft = MovieNight::factory()->create([
            'organizer_id' => $viewer->id,
        ]);

        $ownCancelled = MovieNight::factory()->cancelled()->create([
            'organizer_id' => $viewer->id,
        ]);

        $joined = MovieNight::factory()->scheduled()->create();
        $joined->participants()->attach($viewer->id);

        $hiddenDraft = MovieNight::factory()->create();
        $hiddenDraft->participants()->attach($viewer->id);

        $unrelated = MovieNight::factory()->scheduled()->create();

        $this->signIn($viewer);

        $first = $this->getJson(self::BASE.'?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');

        $second = $this->getJson(self::BASE.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );

        $this->assertEqualsCanonicalizing([
            $ownDraft->id,
            $ownCancelled->id,
            $joined->id,
        ], $ids);

        $this->assertNotContains($hiddenDraft->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);

        $this->getJson(self::BASE.'?status=scheduled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $joined->id);

        $this->getJson(self::BASE.'?page=99')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_unrelated_users_and_admins_receive_private_404s(): void
    {
        $night = MovieNight::factory()->scheduled()->create([
            'title' => 'Private movie night',
        ]);

        $stranger = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        foreach ([$stranger, $admin] as $actor) {
            $this->signIn($actor);

            foreach ([
                ['GET', self::BASE.'/'.$night->id],
                ['PATCH', self::BASE.'/'.$night->id],
                ['POST', self::BASE.'/'.$night->id.'/cancel'],
            ] as [$method, $url]) {
                $this->json($method, $url, [])
                    ->assertNotFound()
                    ->assertJsonMissingPath('data');
            }
        }

        $this->assertSame('scheduled', $night->refresh()->status);
        $this->assertSame('Private movie night', $night->title);
    }

    #[DataProvider('participantReadableStatuses')]
    public function test_member_can_read_published_nights(string $status): void
    {
        $night = $status === 'finalized'
            ? MovieNight::factory()->finalized()->create()
            : MovieNight::factory()->create(['status' => $status]);

        $member = User::factory()->create();
        $night->participants()->attach($member->id);

        $this->signIn($member);

        $this->getJson(self::BASE.'/'.$night->id)
            ->assertOk()
            ->assertJsonPath('data.viewer_role', 'participant')
            ->assertJsonPath('data.permissions.update', false)
            ->assertJsonPath('data.permissions.cancel', false)
            ->assertJsonPath('data.summaries.participants.count', 1)
            ->assertJsonMissingPath('data.organizer.email');
    }

    public static function participantReadableStatuses(): array
    {
        return [
            ['scheduled'],
            ['finalized'],
            ['cancelled'],
        ];
    }

    public function test_members_cannot_read_drafts_or_manage_nights(): void
    {
        $member = User::factory()->create();
        $draft = MovieNight::factory()->create();
        $scheduled = MovieNight::factory()->scheduled()->create();

        $draft->participants()->attach($member->id);
        $scheduled->participants()->attach($member->id);

        $this->signIn($member);

        $this->getJson(self::BASE.'/'.$draft->id)->assertNotFound();

        $this->patchJson(self::BASE.'/'.$scheduled->id, [])
            ->assertForbidden();

        $this->postJson(self::BASE.'/'.$scheduled->id.'/cancel')
            ->assertForbidden();

        $this->assertSame('scheduled', $scheduled->refresh()->status);
    }

    public function test_removing_membership_removes_read_access(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $member = User::factory()->create();

        $night->participants()->attach($member->id);
        $this->signIn($member);

        $this->getJson(self::BASE.'/'.$night->id)->assertOk();

        $night->participants()->detach($member->id);

        $this->getJson(self::BASE.'/'.$night->id)->assertNotFound();
        $this->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_creation_is_rejected(
        array $changes,
        string $field,
    ): void {
        $this->signIn(User::factory()->create());

        $this->postJson(self::BASE, $this->payload($changes))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('movie_nights', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            [['title' => ' '], 'title'],
            [['starts_at' => null], 'starts_at'],
            [['starts_at' => '2026-11-29T20:00:00'], 'starts_at'],
            [['starts_at' => '2026-02-30T20:00:00Z'], 'starts_at'],
            [['starts_at' => '2020-01-01T00:00:00Z'], 'starts_at'],
            [['timezone' => 'Not/A_Zone'], 'timezone'],
            [['meeting_url' => 'javascript:alert(1)'], 'meeting_url'],
            [['description' => str_repeat('x', 2001)], 'description'],
            [['status' => 'finalized'], 'status'],
            [['status' => 'cancelled'], 'status'],
            [['organizer_id' => 999], 'organizer_id'],
            [['winning_movie_id' => 999], 'winning_movie_id'],
            [['participant_ids' => [999]], 'participant_ids'],
        ];
    }

    public function test_organizer_can_update_schedule_and_cancel_atomically(): void
    {
        $night = MovieNight::factory()->create([
            'description' => 'Keep this unless explicitly cleared.',
        ]);

        $this->signIn($night->organizer);

        $this->patchJson(self::BASE.'/'.$night->id, [
            'title' => 'Updated title',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath(
                'data.description',
                'Keep this unless explicitly cleared.',
            );

        $this->patchJson(self::BASE.'/'.$night->id, [
            'description' => null,
            'location' => null,
            'meeting_url' => null,
            'status' => 'scheduled',
        ])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.description', null);

        $this->patchJson(self::BASE.'/'.$night->id, [
            'title' => 'Must not be partially saved',
            'status' => 'draft',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('Updated title', $night->refresh()->title);

        $this->postJson(self::BASE.'/'.$night->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.permissions.update', false);

        $this->patchJson(self::BASE.'/'.$night->id, [
            'title' => 'Not allowed',
        ])->assertForbidden();

        $this->postJson(self::BASE.'/'.$night->id.'/cancel')
            ->assertForbidden();
    }

    public function test_expired_draft_cannot_be_scheduled_without_new_start(): void
    {
        $night = MovieNight::factory()->create([
            'starts_at' => now()->subDay(),
        ]);

        $this->signIn($night->organizer);

        $this->patchJson(self::BASE.'/'.$night->id, [
            'status' => 'scheduled',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertSame('draft', $night->refresh()->status);
    }

    public function test_create_can_schedule_and_cancellation_preserves_winner(): void
    {
        $owner = User::factory()->create();
        $this->signIn($owner);

        $this->postJson(self::BASE, $this->payload([
            'status' => 'scheduled',
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');

        $finalized = MovieNight::factory()->finalized()->create([
            'organizer_id' => $owner->id,
        ]);

        $winnerId = $finalized->winning_movie_id;

        $this->patchJson(self::BASE.'/'.$finalized->id, [
            'title' => 'No changes after finalization',
        ])->assertForbidden();

        $this->postJson(self::BASE.'/'.$finalized->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.winning_movie_id', $winnerId)
            ->assertJsonPath(
                'data.winning_movie.local_movie_id',
                $winnerId,
            );
    }

    public function test_empty_patch_and_nonempty_cancel_are_rejected(): void
    {
        $night = MovieNight::factory()->create();
        $this->signIn($night->organizer);

        $this->patchJson(self::BASE.'/'.$night->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movie_night');

        $this->patchJson(self::BASE.'/'.$night->id.'?title=Forged', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('movie_night');

        $this->postJson(self::BASE.'/'.$night->id.'/cancel', [
            'status' => 'cancelled',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('draft', $night->refresh()->status);
    }

    public function test_listing_and_identifiers_are_validated(): void
    {
        $this->signIn(User::factory()->create());

        foreach ([
            ['page=0', 'page'],
            ['per_page=51', 'per_page'],
            ['status=unknown', 'status'],
            ['user_id=999', 'user_id'],
        ] as [$query, $field]) {
            $this->getJson(self::BASE.'?'.$query)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        foreach (['0', '01', 'abc', '999999', '9223372036854775808'] as $id) {
            $this->getJson(self::BASE.'/'.$id)->assertNotFound();
        }
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'title' => 'Weekend movies',
            'starts_at' => now()->addWeek()->toISOString(),
            'timezone' => 'UTC',
        ], $changes);
    }

    private function signIn(User $user): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }
}