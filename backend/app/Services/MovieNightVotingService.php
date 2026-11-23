<?php

namespace App\Services;

use App\Models\Movie;
use App\Models\MovieNight;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MovieNightVotingService
{
    public function __construct(private MovieNightAccessService $access) {}

    public function summary(User $actor, MovieNight $night): array
    {
        return $this->access->forViewing($actor, $night, fn (MovieNight $current) => $this->summarize($actor, $current));
    }

    public function propose(User $actor, MovieNight $night, int $movieId): array
    {
        return $this->access->forVote($actor, $night, function (MovieNight $current) use ($actor, $movieId): array {
            $this->requireActiveMovie($movieId, 'movie_id');

            if ($this->candidates($current)->where('movie_id', $movieId)->exists()) {
                throw ValidationException::withMessages(['movie_id' => 'This movie is already on the shortlist.']);
            }

            DB::table('movie_night_candidates')->insert([
                'movie_night_id' => $current->getKey(),
                'movie_id' => $movieId,
                'proposed_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->summarize($actor, $current);
        });
    }

    public function vote(User $actor, MovieNight $night, int $candidateId): array
    {
        return $this->access->forVote($actor, $night, function (MovieNight $current) use ($actor, $candidateId): array {
            $movieId = $this->candidates($current)->where('id', $candidateId)->value('movie_id');

            if ($movieId === null) {
                throw ValidationException::withMessages(['candidate_id' => 'Choose a candidate from this movie night.']);
            }

            $this->requireActiveMovie((int) $movieId, 'candidate_id');

            DB::table('movie_night_votes')->upsert([
                'movie_night_id' => $current->getKey(),
                'user_id' => $actor->getKey(),
                'candidate_id' => $candidateId,
                'created_at' => now(),
                'updated_at' => now(),
            ], ['movie_night_id', 'user_id'], ['candidate_id', 'updated_at']);

            return $this->summarize($actor, $current);
        });
    }

    public function finalize(User $actor, MovieNight $night, ?int $candidateId): array
    {
        return $this->access->forFinalization($actor, $night, function (MovieNight $current) use ($actor, $candidateId): array {
            $leaders = $this->leaders($this->tally($current));

            if ($leaders->isEmpty()) {
                throw ValidationException::withMessages(['candidate_id' => 'Add an active movie before finalizing.']);
            }

            $winner = $candidateId === null && $leaders->count() === 1
                ? $leaders->first()
                : $leaders->firstWhere('id', $candidateId);

            if ($winner === null) {
                throw ValidationException::withMessages(['candidate_id' => 'Choose a candidate tied for the highest vote count, or the sole leader.']);
            }

            $this->requireActiveMovie((int) $winner->movie_id, 'candidate_id');

            $current->winning_movie_id = $winner->movie_id;
            $current->status = MovieNight::STATUS_FINALIZED;
            $current->save();

            return $this->summarize($actor, $current);
        });
    }

    private function summarize(User $actor, MovieNight $night): array
    {
        $candidates = $this->tally($night);
        $viewerVote = DB::table('movie_night_votes')
            ->where('movie_night_id', $night->getKey())
            ->where('user_id', $actor->getKey())
            ->value('candidate_id');

        return [
            'status' => $night->status,
            'voting_open' => $night->acceptsVotes(),
            'winning_movie_id' => $night->winning_movie_id,
            'viewer_candidate_id' => $viewerVote === null ? null : (string) $viewerVote,
            'total_votes' => (int) $candidates->sum('vote_count'),
            'leading_candidate_ids' => $night->acceptsVotes()
                ? $this->leaders($candidates)->map(fn ($candidate) => (string) $candidate->id)->all()
                : [],
            'candidates' => $candidates->map(fn ($candidate) => [
                'id' => (string) $candidate->id,
                'proposed_by' => $candidate->proposed_by === null ? null : (string) $candidate->proposed_by,
                'movie' => [
                    'local_movie_id' => (string) $candidate->movie_id,
                    'tmdb_id' => $candidate->tmdb_id === null ? null : (int) $candidate->tmdb_id,
                    'source' => $candidate->source,
                    'title' => $candidate->title,
                    'is_active' => $candidate->deactivated_at === null,
                ],
                'vote_count' => (int) $candidate->vote_count,
            ])->all(),
        ];
    }

    private function candidates(MovieNight $night): Builder
    {
        return DB::table('movie_night_candidates as candidates')
            ->where('candidates.movie_night_id', $night->getKey());
    }

    private function tally(MovieNight $night): Collection
    {
        return $this->candidates($night)
            ->join('movies', 'movies.id', '=', 'candidates.movie_id')
            ->select('candidates.id', 'candidates.movie_id', 'candidates.proposed_by',
                'movies.tmdb_id', 'movies.source', 'movies.title', 'movies.deactivated_at')
            ->selectSub(
                DB::table('movie_night_votes')->selectRaw('count(*)')
                    ->whereColumn('movie_night_id', 'candidates.movie_night_id')
                    ->whereColumn('candidate_id', 'candidates.id'),
                'vote_count',
            )
            ->orderBy('candidates.id')
            ->get();
    }

    private function leaders(Collection $candidates): Collection
    {
        $active = $candidates->whereNull('deactivated_at');

        return $active->where('vote_count', $active->max('vote_count'))->values();
    }

    private function requireActiveMovie(int $movieId, string $field): void
    {
        $movie = Movie::query()->lockForUpdate()->find($movieId);

        if ($movie === null || $movie->deactivated_at !== null) {
            throw ValidationException::withMessages([$field => 'Choose an active catalog movie.']);
        }
    }
}
