<?php

namespace Tests\Feature;

use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovieNightMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_relationships_have_timestamps(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $member = User::factory()->create();

        $night->participants()->attach($member->id);

        $related = $night->participants()->sole();

        $this->assertTrue($related->is($member));
        $this->assertNotNull($related->pivot->created_at);
        $this->assertTrue(
            $member->participatingMovieNights()->sole()->is($night)
        );
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $night = MovieNight::factory()->create();
        $member = User::factory()->create();

        $night->participants()->attach($member->id);

        $this->expectException(QueryException::class);

        $night->participants()->attach($member->id);
    }

    public function test_missing_participant_is_rejected(): void
    {
        $night = MovieNight::factory()->create();

        $this->expectException(QueryException::class);

        $night->participants()->attach(999999);
    }

    public function test_deleting_member_removes_membership_and_preserves_night(): void
    {
        $night = MovieNight::factory()->create();
        $member = User::factory()->create();

        $night->participants()->attach($member->id);
        $member->delete();

        $this->assertModelExists($night);
        $this->assertDatabaseCount('movie_night_participants', 0);
    }

    public function test_deleting_night_removes_membership_and_preserves_users(): void
    {
        $night = MovieNight::factory()->create();
        $member = User::factory()->create();
        $organizer = $night->organizer;

        $night->participants()->attach($member->id);
        $night->delete();

        $this->assertModelExists($member);
        $this->assertModelExists($organizer);
        $this->assertDatabaseCount('movie_night_participants', 0);
    }
}