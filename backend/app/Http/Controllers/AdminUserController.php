<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'between:1,2147483647'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        $users = User::query()
            ->select(['id', 'name', 'email', 'is_admin', 'disabled_at', 'created_at'])
            ->orderBy('id');

        if (isset($filters['search'])) {
            $pattern = '%'.strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $users->where(fn ($query) => $query
                ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]));
        }

        $page = $users->paginate(
            (int) ($filters['per_page'] ?? 20),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $user): array => $this->summary($user)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        $id = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false || (string) $id !== $userId, 404, 'User not found.');

        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $values = Validator::make($body, [
            'status' => ['required', Rule::in(['enabled', 'disabled'])],
        ])->validate();
        $disabled = $values['status'] === 'disabled';

        $user = DB::transaction(function () use ($id, $disabled): User {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            abort_if($user->is_admin, 403, 'Administrator accounts are protected.');

            if ($disabled && $user->disabled_at === null) {
                $user->forceFill(['disabled_at' => now(), 'remember_token' => Str::random(60)])->save();
                $user->revokeDatabaseSessions();
            } elseif (! $disabled && $user->disabled_at !== null) {
                $user->forceFill(['disabled_at' => null])->save();
            }

            return $user;
        });

        return response()->json(['data' => $this->summary($user)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function summary(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'status' => $user->disabled_at === null ? 'enabled' : 'disabled',
            'disabled_at' => $user->disabled_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
