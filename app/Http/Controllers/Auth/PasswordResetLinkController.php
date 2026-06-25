<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ResetPasswordCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordResetLinkController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Security: always return 200
        if (!$user) {
            return response()->json(['message' => 'If this email exists, code sent'], 200);
        }

        // 1. Generate 6-digit code
        $code = str_pad(random_int(0, 999), 6, '0', STR_PAD_LEFT);

        // 2. Delete old codes + insert new hashed code
        DB::table('password_resets')->where('email', $request->email)->delete();
        DB::table('password_resets')->insert([
            'email' => $request->email,
            'token' => Hash::make($code),
            'created_at' => now()
        ]);

        // 3. Send ONLY our custom notification
        $user->notify(new ResetPasswordCode($code));

        return response()->json(['message' => '6-digit code sent to your mail'], 200);
    }
}