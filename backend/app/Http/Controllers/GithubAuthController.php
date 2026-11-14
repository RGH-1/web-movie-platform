<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GithubAuthController extends Controller
{
    private bool $linking = false;

    public function link(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json(['message' => 'A stateful session is required.'], 419);
        }
        $request->validate(['current_password' => ['required', 'string', 'current_password:web']]);
        if (! $this->configured()) {
            return response()->json(['message' => 'GitHub sign-in is not configured.'], 503);
        }

        return response()->json(['data' => ['url' => $this->authorizationUrl($request, (int) $request->user()->id)]]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return $this->finish();
        }
        if (! $this->configured()) {
            return $this->finish('unavailable');
        }

        return redirect()->away($this->authorizationUrl($request));
    }

    private function configured(): bool
    {
        return (bool) (config('services.github.client_id') && config('services.github.client_secret') && config('services.github.redirect'));
    }

    private function authorizationUrl(Request $request, ?int $linkUserId = null): string
    {
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('oauth.github', [
            'state' => $state, 'verifier' => $verifier, 'expires' => time() + 600,
            'link_user_id' => $linkUserId,
        ]);

        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => config('services.github.redirect'),
            'scope' => 'read:user user:email',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->linking = false;
        $pending = $request->session()->pull('oauth.github');
        $state = $request->query('state');
        if (! is_array($pending) || ! is_string($state) || ! hash_equals($pending['state'], $state) || $pending['expires'] < time()) {
            return $this->finish('invalid_state');
        }
        $this->linking = isset($pending['link_user_id']);
        if ($this->linking && (int) Auth::guard('web')->id() !== (int) $pending['link_user_id']) {
            $this->linking = false;

            return $this->finish('invalid_state');
        }
        if (! $this->linking && Auth::guard('web')->check()) {
            return $this->finish();
        }
        if ($request->query('error')) {
            return $this->finish('denied');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 1024) {
            return $this->finish('failed');
        }

        try {
            $token = Http::asForm()->acceptJson()->timeout(10)->post('https://github.com/login/oauth/access_token', [
                'client_id' => config('services.github.client_id'),
                'client_secret' => config('services.github.client_secret'),
                'redirect_uri' => config('services.github.redirect'),
                'code' => $code, 'code_verifier' => $pending['verifier'],
            ])->throw()->json('access_token');
            if (! is_string($token) || $token === '') {
                return $this->finish('failed');
            }
            $client = Http::withToken($token)->acceptJson()->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])->timeout(10);
            $profile = $client->get('https://api.github.com/user')->throw()->json();
            $id = $profile['id'] ?? null;
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                return $this->finish('failed');
            }
            if ($this->linking) {
                return $this->linkIdentity((int) $pending['link_user_id'], (string) $id);
            }
            $user = $this->findUser((string) $id);
            if (! $user) {
                // Public profile email is not proof of verification. Only trust /user/emails.
                $emailsResponse = $client->get('https://api.github.com/user/emails');
                $email = null;
                if ($emailsResponse->successful() && is_array($emailsResponse->json())) {
                    foreach ($emailsResponse->json() as $candidate) {
                        if (is_array($candidate) && ($candidate['verified'] ?? false) === true && ($candidate['primary'] ?? false) === true
                            && is_string($candidate['email'] ?? null) && strlen($candidate['email']) <= 255
                            && filter_var($candidate['email'], FILTER_VALIDATE_EMAIL)) {
                            $email = strtolower($candidate['email']);
                            break;
                        }
                    }
                }
                // Never attach a provider to an existing account based on email alone.
                if ($email && User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    return $this->finish('email_conflict');
                }
                try {
                    $user = DB::transaction(function () use ($id, $email, $profile) {
                        $existing = $this->findUser((string) $id);
                        if ($existing) {
                            return $existing;
                        }
                        $name = $profile['name'] ?? $profile['login'] ?? 'GitHub user';
                        $user = User::create([
                            'name' => is_string($name) && trim($name) !== '' ? Str::limit(trim($name), 255, '') : 'GitHub user',
                            'email' => $email,
                            'password' => Str::random(64),
                        ]);
                        $user->forceFill(['email_verified_at' => $email ? now() : null])->save();
                        DB::table('social_accounts')->insert([
                            'user_id' => $user->id, 'provider' => 'github', 'provider_id' => (string) $id,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);

                        return $user;
                    });
                } catch (UniqueConstraintViolationException) {
                    // The unique provider index arbitrates concurrent callbacks; the loser rolls back its user.
                    $user = $this->findUser((string) $id);
                    if (! $user) {
                        return $this->finish('email_conflict');
                    }
                }
            }
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return $this->finish();
        } catch (ConnectionException|RequestException) {
            // Never expose provider tokens, codes, or upstream response bodies.
            return $this->finish('failed');
        }
    }

    private function findUser(string $id): ?User
    {
        $userId = DB::table('social_accounts')->where('provider', 'github')->where('provider_id', $id)->value('user_id');

        return $userId ? User::find($userId) : null;
    }

    private function linkIdentity(int $userId, string $providerId): RedirectResponse
    {
        try {
            $linked = DB::transaction(function () use ($userId, $providerId): bool {
                User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $owner = $this->findUser($providerId);
                if ($owner) {
                    return (int) $owner->id === $userId;
                }
                // Never replace an identity already connected to either account.
                if (DB::table('social_accounts')->where('user_id', $userId)->where('provider', 'github')->exists()) {
                    return false;
                }
                DB::table('social_accounts')->insert([
                    'user_id' => $userId, 'provider' => 'github', 'provider_id' => $providerId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            $linked = (int) $this->findUser($providerId)?->id === $userId;
        }

        return $this->finish($linked ? null : 'already_linked');
    }

    private function finish(?string $error = null): RedirectResponse
    {
        $base = rtrim(config('services.github.frontend_url'), '/');

        if ($this->linking) {
            return redirect()->away($base.'/profile?'.($error ? 'github_error='.$error : 'github_linked=1'));
        }

        return redirect()->away($base.($error ? '/login?oauth_error='.$error : '/'));
    }
}
