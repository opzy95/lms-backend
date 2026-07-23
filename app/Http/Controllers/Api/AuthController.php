<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ResetPasswordCode;
use App\Http\Requests\Auth\ChangePasswordRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AuthController extends Controller
{
    // POST /api/register
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:student,tutor', // admin blocked
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
            'message' => $user->role === 'tutor' 
                ? 'Registration successful. Wait for admin approval.' 
                : 'Registration successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'education_level' => $user->education_level,
                'is_approved' => $user->is_approved,
            ],
            'token' => $token
        ], 201);
    }

    // POST /api/login
    public function login(Request $request)
    {
         
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'education_level' => $user->education_level,
                'is_approved' => $user->is_approved,
            ],
            'token' => $token,
            'success' => true,
            'message' => 'Login route working'
        ]);
            
    }
    // Forgot Password - sends 6-digit code to email
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
        
        // Generate 6-digit code
        $code = random_int(100000, 999999);
        
        // Delete any existing codes for this email
        DB::table('password_reset_codes')->where('email', $request->email)->delete();
        
        // Store code with 1 hour expiration
        DB::table('password_reset_codes')->insert([
            'email' => $request->email,
            'code' => $code,
            'expires_at' => Carbon::now()->addHour(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        
        // Send code via Resend
        try {
            $notification = new ResetPasswordCode($code);
            $notification->send($user);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            \Log::error('Failed to send password reset code: ' . $errorMessage);
            return response()->json([
                'message' => 'Failed to send code. Error: ' . $errorMessage
            ], 500);
        }

        return response()->json(['message' => '6-digit code sent to your email']);
    }

    // Reset Password with 6-digit code
    public function resetPassword(Request $request)
    {
        $request->validate([
            'code' => 'required|digits:6',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email not found'
            ], 404);
        }

        // Verify code exists and hasn't expired
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
        
        // Update password
        $user->update(['password' => Hash::make($request->password)]);
        
        // Delete used code
        DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->delete();

        return response()->json([
            'message' => 'Password reset successful'
        ], 200);
    }

    // Change Password - requires old password verification
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();

        // Verify current password matches
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        // Prevent using same password as new password
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'message' => 'New password must be different from current password'
            ], 422);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'message' => 'Password changed successfully'
        ], 200);
    }

    // Logout
    
    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }
}