<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'login'    => 'required|string',                  // email or 10 digit mobile number
            'password' => 'required|string',
            'role'     => 'required|in:super_admin,retailer', // "Sign in as" selection
            'remember' => 'sometimes|boolean',                // "Keep me signed in" checkbox
        ]);

        // Find the user by email or mobile number
        $user = User::where('email', $data['login'])
            ->orWhere('mobile', $data['login'])
            ->first();

        // Same message for unknown user and wrong password (prevents user enumeration)
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // The typed login is NOT saved: people sometimes type their password in that box
            Audit::log($request, 'auth.login_failed', 'user', $user?->id ?? 0, ['role' => $data['role']]);

            return response()->json(['message' => 'Invalid credentials. Please check and try again.'], 422);
        }

        if (! $user->isActive()) {
            Audit::log($request, 'auth.login_blocked', 'user', $user->id, ['reason' => 'deactivated']);

            return response()->json(['message' => 'Your account is deactivated. Please contact the administrator.'], 403);
        }

        // The selected tab must match the real role stored in the database
        if ($user->role !== $data['role']) {
            Audit::log($request, 'auth.login_blocked', 'user', $user->id, ['reason' => 'wrong_role', 'tried' => $data['role']]);

            $label = $data['role'] === 'super_admin' ? 'Super Admin' : 'Retailer';

            return response()->json(['message' => "This account cannot sign in as {$label}."], 403);
        }

        // Remember me: 30 days, otherwise 8 hours
        $expiresAt = ($data['remember'] ?? false) ? now()->addDays(30) : now()->addHours(8);

        $token = $user->createToken('auth_token', ['*'], $expiresAt)->plainTextToken;

        // The user is not authenticated yet at this point, so tell the audit helper who just signed in
        $request->setUserResolver(fn () => $user);
        Audit::log($request, 'auth.login', 'user', $user->id, ['remember' => (bool) ($data['remember'] ?? false)]);

        return response()->json([
            'token'      => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'user'       => $this->userPayload($user),
        ]);
    }

    public function getUser(Request $request)
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request)
    {
        Audit::log($request, 'auth.logout', 'user', $request->user()->id);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'You have been signed out securely.']);
    }

    // Only the fields the frontend needs
    private function userPayload(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'mobile'        => $user->mobile,
            'role'          => $user->role,
            'business_name' => $user->retailer?->business_name,
        ];
    }
}