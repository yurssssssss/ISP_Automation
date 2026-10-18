@extends('layouts.guest')
@section('title', 'Sign in')

@section('card')
<h2 class="h5 mb-3">Sign in</h2>
<form method="POST" action="{{ route('login') }}" novalidate>
    @csrf
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" class="form-control @error('email') is-invalid @enderror" autocomplete="username" required autofocus
               data-email-check="existing" data-email-check-url="{{ route('email.check') }}">
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input id="password" name="password" type="password" maxlength="255" class="form-control @error('password') is-invalid @enderror" autocomplete="current-password" required>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
        <label class="form-check-label" for="remember">Keep me signed in</label>
    </div>
    <button type="submit" class="btn btn-primary w-100">Sign in</button>
</form>

<a href="{{ route('password.request') }}" class="btn btn-outline-secondary w-100 mt-2">Forgot password?</a>

<hr class="my-4">
<p class="text-center small text-body-secondary mb-2">New customer? Contact our office to get connected — we'll create your account.</p>
<p class="text-center small text-body-secondary mb-0">First time signing in? Use <a href="{{ route('password.request') }}">Forgot password</a> with the email you gave us to set your password.</p>
@endsection
