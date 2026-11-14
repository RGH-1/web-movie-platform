<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordController extends Controller
{
    private function passwordRules(): array
    {
        // Bcrypt only considers the first 72 bytes; restrict input accordingly.
        return ['required', 'string', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols(),
            static function ($attribute, $value, $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }];
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => $this->passwordRules(),
        ]);

        DB::transaction(function () use ($request, $data): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'The current password is incorrect. Accounts without a local password must use email password reset.',
                ]);
            }
            $this->updatePassword($user, $data['password']);
            Password::deleteToken($user);
        });

        $this->logout($request);

        return response()->json(['message' => 'Password changed. Please sign in again.']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        try {
            Password::sendResetLink($data);
        } catch (TransportExceptionInterface) {
            // SMTP outages must not disclose account existence or sensitive mail content.
            Log::error('Password reset mail transport failed.');
        }

        return response()->json(['message' => 'If an account exists for this email, a password reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ]);

        // Serialize resets and password changes for this account, including token deletion.
        $status = DB::transaction(function () use ($data) {
            User::query()->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password): void {
                $this->updatePassword($user, $password);
                event(new PasswordReset($user));
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'The reset link is invalid or has expired.']);
        }

        $this->logout($request);

        return response()->json(['message' => 'Password reset. Please sign in again.']);
    }

    private function updatePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $user->id)->delete();
        }
    }

    private function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
