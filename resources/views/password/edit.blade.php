@extends('layouts.app')
@section('title', 'Change Password')

@section('content')
<form method="POST" action="{{ route('password.update') }}" class="card max-w-md space-y-4 p-6">
    @csrf @method('PUT')
    <div>
        <label class="label">Current password</label>
        <input type="password" name="current_password" class="input" required autocomplete="current-password">
    </div>
    <div>
        <label class="label">New password (min 8 characters)</label>
        <input type="password" name="password" class="input" required autocomplete="new-password">
    </div>
    <div>
        <label class="label">Confirm new password</label>
        <input type="password" name="password_confirmation" class="input" required autocomplete="new-password">
    </div>
    <button class="btn-primary">Update password</button>
</form>
@endsection
