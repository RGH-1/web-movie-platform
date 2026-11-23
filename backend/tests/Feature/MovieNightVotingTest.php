<?php

namespace Tests\Feature;

use App\Models\Movie;
use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovieNightVotingTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_voters_can_change_votes_and_only_the_organizer_can_break_a_tie(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $participant = User::factory()->create();
        $outsider = User::factory()->create();
        $first = Movie::factory()->create();
        $second = Movie::factory()->tmdb()->create();
        $url = "/api/v1/movie-nights/{$night->id}";

        $this->signIn($night->organizer);
        $this->postJson("$url/invitations", ['invitee_id' => $participant->id])->assertCreated();
        $firstId = $this->postJson("$url/candidates", ['movie_id' => $first->id])
            ->assertCreated()->json('data.candidates.0.id');
        $this->postJson("$url/candidates", ['movie_id' => $first->id])->assertUnprocessable();

        $this->signIn($participant);
        $this->putJson("$url/vote", ['candidate_id' => $firstId])->assertNotFound();
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertOk();
        $secondId = $this->postJson("$url/candidates", ['movie_id' => $second->id])
            ->assertCreated()->json('data.candidates.1.id');
        $this->putJson("$url/vote", ['candidate_id' => $firstId])->assertOk();
        $this->putJson("$url/vote", ['candidate_id' => $secondId])
            ->assertOk()
            ->assertJsonPath('data.viewer_candidate_id', $secondId)
            ->assertJsonPath('data.total_votes', 1)
            ->assertJsonPath('data.candidates.0.vote_count', 0)
            ->assertJsonPath('data.candidates.1.vote_count', 1);
        $this->assertDatabaseCount('movie_night_votes', 1);
        $this->postJson("$url/finalize")->assertForbidden();

        $this->signIn($outsider);
        $this->getJson("$url/candidates")->assertNotFound();
        $this->putJson("$url/vote", ['candidate_id' => $firstId])->assertNotFound();

        $this->signIn($night->organizer);
        $this->postJson("$url/finalize", ['candidate_id' => $firstId])->assertUnprocessable();
        $this->putJson("$url/vote", ['candidate_id' => $firstId])
            ->assertOk()->assertJsonPath('data.leading_candidate_ids', [$firstId, $secondId]);
        $this->postJson("$url/finalize")->assertUnprocessable();
        $this->postJson("$url/finalize", ['candidate_id' => $secondId])
            ->assertOk()
            ->assertJsonPath('data.winning_movie_id', (string) $second->id)
            ->assertJsonPath('data.voting_open', false)
            ->assertJsonPath('data.total_votes', 2);
        $this->putJson("$url/vote", ['candidate_id' => $secondId])->assertForbidden();
        $this->postJson("$url/candidates", ['movie_id' => $second->id])->assertForbidden();
        $this->postJson("$url/finalize", ['candidate_id' => $firstId])->assertForbidden();
        $this->getJson($url)->assertOk()->assertJsonPath('data.winning_movie_id', (string) $second->id);
    }

    public function test_declining_removes_an_open_vote_and_the_sole_leader_can_be_finalized(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $participant = User::factory()->create();
        $movie = Movie::factory()->create();
        $inactive = Movie::factory()->create(['deactivated_at' => now()]);
        $url = "/api/v1/movie-nights/{$night->id}";

        $this->signIn($night->organizer);
        $this->postJson("$url/finalize")->assertUnprocessable();
        $this->postJson("$url/invitations", ['invitee_id' => $participant->id])->assertCreated();
        $this->postJson("$url/candidates", ['movie_id' => $inactive->id])->assertUnprocessable();
        $candidate = $this->postJson("$url/candidates", ['movie_id' => $movie->id])
            ->assertCreated()->json('data.candidates.0.id');

        $otherNight = MovieNight::factory()->scheduled()->create(['organizer_id' => $night->organizer_id]);
        $this->putJson("/api/v1/movie-nights/{$otherNight->id}/vote", ['candidate_id' => $candidate])
            ->assertUnprocessable();

        $this->signIn($participant);
        $this->patchJson("$url/rsvp", ['status' => 'accepted'])->assertOk();
        $this->putJson("$url/vote", ['candidate_id' => $candidate])->assertOk();
        $this->patchJson("$url/rsvp", ['status' => 'declined'])->assertOk();
        $this->putJson("$url/vote", ['candidate_id' => $candidate])->assertNotFound();

        $this->signIn($night->organizer);
        $this->postJson("$url/finalize")->assertOk()
            ->assertJsonPath('data.total_votes', 0)
            ->assertJsonPath('data.winning_movie_id', (string) $movie->id);
    }

    public function test_database_rejects_two_vote_rows_for_the_same_voter_and_night(): void
    {
        $night = MovieNight::factory()->scheduled()->create();
        $movie = Movie::factory()->create();
        $url = "/api/v1/movie-nights/{$night->id}";
        $this->signIn($night->organizer);
        $candidate = $this->postJson("$url/candidates", ['movie_id' => $movie->id])
            ->assertCreated()->json('data.candidates.0.id');
        $this->putJson("$url/vote", ['candidate_id' => $candidate])->assertOk();

        $this->expectException(QueryException::class);
        DB::table('movie_night_votes')->insert([
            'movie_night_id' => $night->id,
            'user_id' => $night->organizer_id,
            'candidate_id' => $candidate,
        ]);
    }

    private function signIn(User $user): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }
}
