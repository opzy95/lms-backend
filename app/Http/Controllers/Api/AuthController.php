<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ResetPasswordCode;
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
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
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
        $request->validate(['email' => 'required|email|exists:users,email']);

        $user = User::where('email', $request->email)->first();
        
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
        
        // Send code via email
        $user->notify(new ResetPasswordCode($code));

        return response()->json(['message' => '6-digit code sent to your email']);
    }

    // Reset Password with 6-digit code
    public function resetPassword(Request $request)
    {
        $request->validate([
            'code' => 'required|digits:6',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // Verify code exists and hasn't expired
        $resetCode = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->first();
        
        if (!$resetCode || Carbon::parse($resetCode->expires_at)->isPast()) {
            return response()->json(['message' => 'Invalid or expired code'], 400);
        }
        
        // Update password
        $user = User::where('email', $request->email)->first();
        $user->update(['password' => Hash::make($request->password)]);
        
        // Delete used code
        DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->delete();

        return response()->json(['message' => 'Password reset successful']);
    }

    // Logout
    
    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }
}