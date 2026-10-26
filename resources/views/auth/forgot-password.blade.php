@extends('layouts.guest')
@section('title', 'Reset password')

@section('card')
<h2 class="h5 mb-2">Reset or set your password</h2>
<p class="text-body-secondary small">Enter the email on your account and we'll send you a 6-digit code.</p>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', is_string(request()->query('email')) ? request()->query('email') : '') }}" class="form-control @error('email') is-invalid @enderror" required autofocus
               data-email-check="existing" data-email-check-url="{{ route('email.check') }}">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <button class="btn btn-primary w-100">Send code</button>
</form>
<div class="text-center mt-3"><a href="{{ route('login') }}">Back to sign in</a></div>
@endsection
