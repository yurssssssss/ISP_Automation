@extends('layouts.guest')
@section('title', 'Sign in')

@section('card')
<div class="auth-badge">@include('partials.icon', ['name' => 'wifi'])</div>
<h1 class="auth-title">Welcome back</h1>
<p class="auth-sub">Sign in to your {{ config('app.name') }} account</p>

<form method="POST" action="{{ route('login') }}" novalidate>
    @csrf
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <div class="field-icon">
            @include('partials.icon', ['name' => 'user'])
            <input id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255" class="form-control @error('email') is-invalid @enderror" placeholder="you@example.com" autocomplete="username" required autofocus
                   data-email-check="existing" data-email-check-url="{{ route('email.check') }}">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <div class="field-icon">
            @include('partials.icon', ['name' => 'lock'])
            <input id="password" name="password" type="password" maxlength="255" class="form-control has-toggle @error('password') is-invalid @enderror" placeholder="Your password" autocomplete="current-password" required>
            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false">
                @include('partials.icon', ['name' => 'eye', 'class' => 'icon-show'])
                @include('partials.icon', ['name' => 'eye-off', 'class' => 'icon-hide'])
            </button>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" @checked(old('remember'))>
        <label class="form-check-label" for="remember">Keep me signed in</label>
    </div>
    <button type="submit" class="btn btn-primary btn-lg w-100 d-flex justify-content-center align-items-center gap-2">
        Sign in @include('partials.icon', ['name' => 'arrow'])
    </button>
</form>

<a href="{{ route('password.request') }}" class="btn btn-light-outline w-100 mt-2">Forgot password?</a>

<div class="auth-divider"></div>
<p class="text-center small text-body-secondary mb-2">New customer? <a href="{{ route('landing') }}#contact">Contact our office</a> to get connected — we'll create your account.</p>
<p class="text-center small text-body-secondary mb-0">First time signing in? Use <a href="{{ route('password.request') }}">Forgot password</a> with the email you gave us to set your password.</p>
@endsection
