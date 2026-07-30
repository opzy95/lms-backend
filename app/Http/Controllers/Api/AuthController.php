<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Notifications\ResetPasswordCode;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register
     */
   public function register(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:users',
        'password' => 'required|string|min:8|confirmed',
        'role' => 'required|in:student,tutor',
        'education_level' => 'required|in:basic,secondary',
    ]);

    $user = User::create([
        'name' => $request->name,
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'role' => $request->role,
        'education_level' => $request->education_level,
        'is_approved' => $request->role === 'tutor' ? false : true,
    ]);

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Registration successful',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'education_level' => $user->education_level,
            'is_approved' => $user->is_approved,
        ],
    ]);
}

    
public function login(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($request->only('email', 'password'))) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $user = Auth::user();
    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'education_level' => $user->education_level,
            'is_approved' => $user->is_approved,
        ],
    ]);
}

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        if ($request->user()) {
            $request->user()->tokens()?->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Refresh Token
     */
  public function refresh(Request $request)
{
    $user = $request->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 401);
    }

    $currentToken = $user->currentAccessToken();

    if (!$currentToken) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 401);
    }

    $currentToken->delete();

    $token = $user->createToken('auth_token')->plainTextToken;

   return response()
    ->json([
        'success' => true,
        'message' => 'Token refreshed',
        // 'expires_at' => now()->addDays(7)->toISOString(),
    ])
    ->cookie(
        'auth_token',
        $token,
        60 * 24 * 7,
        '/',
        null,
        app()->environment('production'),
        true,
        false,
        'Lax'
    );
}
    /**
     * Reset Password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => 'required|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email not found'
            ], 404);
        }

        $resetCode = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (!$resetCode) {
            return response()->json([
                'message' => 'Invalid code'
            ], 400);
        }

        if (Carbon::parse($resetCode->expires_at)->isPast()) {
            return response()->json([
                'message' => 'Code has expired'
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successful'
        ]);
    }

    public function forgotPassword(Request $request)
{
    $request->validate([
        'email' => 'required|email',
    ]);

    $user = User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json([
            'message' => 'Email not found'
        ], 404);
    }

    $code = random_int(100000, 999999);

    DB::table('password_reset_codes')
        ->where('email', $request->email)
        ->delete();

    DB::table('password_reset_codes')->insert([
        'email' => $request->email,
        'code' => $code,
        'expires_at' => Carbon::now()->addMinutes(10),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        (new ResetPasswordCode($code))->send($user);
    } catch (\Exception $e) {
        Log::error($e->getMessage());

        return response()->json([
            'message' => 'Unable to send reset code.'
        ], 500);
    }

    return response()->json([
        'success' => true,
        'message' => '6-digit code sent successfully.'
    ]);
}

    /**
     * Change Password
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'message' => 'New password must be different.'
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }
}