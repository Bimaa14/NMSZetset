<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditRecorder;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function update(Request $r, AuditRecorder $audit)
    {
        $r->merge(['username' => User::normalizeUsername((string) $r->input('username', ''))]);
        $d = $r->validate([
            'name' => 'required|string|max:100',
            'username' => User::usernameRules($r->user()->id),
            'current_password' => 'required|current_password',
            'password' => 'nullable|string|min:6|max:255|confirmed',
        ], [
            'username.regex' => User::USERNAME_REGEX_MESSAGE,
        ]);
        $user = $r->user();
        $user->name = $d['name'];
        $user->username = $d['username'];
        if (!empty($d['password'])) {
            $user->password = $d['password'];
        }
        $user->save();
        $audit->record($r, 'profile.update', $user->name);
        $r->session()->regenerate();

        return response()->json(['message' => 'Profil disimpan.', 'csrf_token' => csrf_token()]);
    }
}
