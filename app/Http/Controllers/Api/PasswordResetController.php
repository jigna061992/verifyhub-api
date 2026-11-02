<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

// Forgot password + reset password (public routes, no login needed)
class PasswordResetController extends Controller
{
    // Step 1: user types the email, we send a reset link
    public function forgot(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Only active accounts get a mail. We do NOT tell the caller which case happened,
        // so nobody can use this form to find out which emails are registered.
        if ($user && $user->isActive()) {
            // Laravel makes the token, stores only its hash in password_reset_tokens and sends the mail.
            // If the same user asks again within 60 seconds Laravel skips it (built-in throttle).
            Password::sendResetLink(['email' => $user->email]);

            $request->setUserResolver(fn () => $user);
            Audit::log($request, 'auth.password_reset_requested', 'user', $user->id);
        } else {
            // The typed email is NOT saved in the audit log
            Audit::log($request, 'auth.password_reset_requested', 'user', 0, ['known' => false]);
        }

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return response()->json([
            'message' => "If this email is registered with VerifyHub, a password reset link has been sent to it. The link is valid for {$minutes} minutes.",
        ]);
    }

    // Step 2: user opens the link, types the new password
    public function reset(Request $request)
    {
        $data = $request->validate([
            'token'    => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:100', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        $invalid = ValidationException::withMessages([
            'email' => 'This reset link is invalid or has expired. Please request a new one.',
        ]);

        // Deactivated accounts cannot reset (same message, nothing is revealed)
        $existing = User::where('email', $data['email'])->first();
        if (! $existing || ! $existing->isActive()) {
            throw $invalid;
        }

        $resetUser = null;

        $status = Password::reset(
            [
                'email'                 => $data['email'],
                'password'              => $data['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token'                 => $data['token'],
            ],
            function (User $user, string $password) use (&$resetUser) {
                // The model cast "hashed" hashes the password
                $user->forceFill([
                    'password'       => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Sign out every device (all Sanctum tokens)
                $user->tokens()->delete();

                $resetUser = $user;

                event(new PasswordReset($user));
            }
        );

        // Wrong token, expired token (60 min) or already used: all give the same answer
        if ($status !== Password::PASSWORD_RESET || ! $resetUser) {
            throw $invalid;
        }

        $request->setUserResolver(fn () => $resetUser);
        Audit::log($request, 'auth.password_reset', 'user', $resetUser->id);

        return response()->json([
            'message' => 'Your password has been changed. Please sign in with the new password.',
        ]);
    }
}
