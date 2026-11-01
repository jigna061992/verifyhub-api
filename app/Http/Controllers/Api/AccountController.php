<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

// "My account" for any logged-in user (super admin or retailer)
class AccountController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('retailer');
        $r    = $user->retailer;

        return response()->json([
            'name'         => $user->name,
            'email'        => $user->email,
            'mobile'       => $user->mobile,
            'role'         => $user->role,
            'member_since' => $user->created_at?->toIso8601String(),
            'retailer'     => $r ? [
                'code'          => 'RT' . (1000 + $r->id),
                'business_name' => $r->business_name,
                'address'       => $r->address,
                'status'        => $r->status,
                'pan_masked'    => $this->mask($r->pan),
                'gst_masked'    => $this->mask($r->gst),
            ] : null,
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'max:100', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'The two new passwords do not match.',
            'password.different' => 'The new password must be different from the current one.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => $data['password']]); // hashed by the model cast

        // Sign out every OTHER device. This session stays signed in.
        $currentId = $user->currentAccessToken()->id ?? null;
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        Audit::log($request, 'account.password_changed', 'user', $user->id);

        return response()->json(['message' => 'Password changed. You were signed out from your other devices.']);
    }

    // XXXXXX1234F style masking: only the last 4 characters stay visible
    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $len = strlen($value);

        return $len <= 4 ? str_repeat('X', $len) : str_repeat('X', $len - 4) . substr($value, -4);
    }
}
