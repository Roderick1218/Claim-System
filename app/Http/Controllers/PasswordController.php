<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    public function edit()
    {
        return view('password.edit');
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $request->input('password')]);
        $audit->log('user.password_changed', 'user', $request->user()->id);

        return back()->with('success', 'Password updated.');
    }
}
