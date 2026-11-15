<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

class OAuthController extends Controller
{
    private function validateProvider(string $provider): void
    {
        if (! in_array($provider, config('oauth.providers', []), true)) {
            abort(404, 'Unsupported OAuth provider.');
        }
    }

    private function providerConfigured(string $provider): bool
    {
        $credentials = config("services.{$provider}", []);

        return ! empty($credentials['client_id'])
            && ! empty($credentials['client_secret']);
    }

    private function frontendUrl(): string
    {
        return rtrim(config('oauth.frontend_url'), '/');
    }

    private function fail(string $code): RedirectResponse
    {
        return redirect()->away(
            $this->frontendUrl() . '/login?' . http_build_query([
                'oauth_error' => $code,
            ])
        );
    }

    public function redirect(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        if (Auth::guard('web')->check()) {
            abort(409, 'Already authenticated.');
        }

        if (! $this->providerConfigured($provider)) {
            abort(503, 'This OAuth provider is not configured.');
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(
        Request $request,
        string $provider
    ): RedirectResponse {
        $this->validateProvider($provider);

        if (Auth::guard('web')->check()) {
            abort(409, 'Already authenticated.');
        }

        if ($request->filled('error')) {
            return $this->fail('cancelled');
        }

        if (! $this->providerConfigured($provider)) {
            return $this->fail('provider_unavailable');
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return $this->fail('invalid_state');
        } catch (Throwable) {
            return $this->fail('provider_failed');
        }

        $providerUserId = trim((string) $socialUser->getId());

        if ($providerUserId === '' || strlen($providerUserId) > 255) {
            return $this->fail('invalid_identity');
        }

        $socialAccount = SocialAccount::with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;
        } else {
            $email = $socialUser->getEmail();
            $facebookWithoutEmail = $provider === 'facebook'
                && ($email === null || (is_string($email) && trim($email) === ''));

            if ($facebookWithoutEmail) {
                $email = null;
            } elseif (
                ! is_string($email) ||
                Validator::make(
                    ['email' => $email],
                    ['email' => ['required', 'email:rfc', 'max:255']]
                )->fails()
            ) {
                return $this->fail($provider === 'facebook' ? 'invalid_email' : 'email_required');
            } else {
                $email = strtolower(trim($email));
            }


            if ($provider === 'google') {
                if (! ($socialUser instanceof SocialiteUser)) {
                    return $this->fail('invalid_identity');
                }

                if (($socialUser->getRaw()['email_verified'] ?? false) !== true) {
                    return $this->fail('email_unverified');
                }
            }
            $name = trim((string) (
                $socialUser->getName()
                ?: $socialUser->getNickname()
                ?: ($email === null ? 'Facebook User' : explode('@', $email)[0])
            ));

            $name = mb_substr($name, 0, 255);

            if ($name === '') {
                $name = 'Social User';
            }

            try {
                $user = DB::transaction(function () use (
                    $provider,
                    $providerUserId,
                    $email,
                    $name
                ) {
                    // A NULL email is not an account identity. Only a supplied
                    // provider email can conflict with a local account.
                    if ($email !== null && User::where('email', $email)->exists()) {
                        return null;
                    }

                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'password' => null,
                    ]);

                    $user->socialAccounts()->create([
                        'provider' => $provider,
                        'provider_user_id' => $providerUserId,
                        'provider_email' => $email,
                    ]);

                    return $user;
                });
            } catch (UniqueConstraintViolationException) {
                return $this->fail('account_conflict');
            }

            if (! $user) {
                return $this->fail('account_exists');
            }
        }

        if (! $user) {
            return $this->fail('account_unavailable');
        }

        Auth::guard('web')->login($user);

        $request->session()->regenerate();

        return redirect()->away($this->frontendUrl());
    }
}
