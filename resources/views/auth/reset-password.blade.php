@extends('layouts.guest')
@section('title', 'Choose a new password')

@section('card')
<h2 class="h5 mb-3">Choose a new password</h2>
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" class="form-control" required
               data-email-check="existing" data-email-check-url="{{ route('email.check') }}">
    </div>
    @include('auth.partials.code-input')
    <div class="mb-3">
        <label class="form-label" for="password">New password</label>
        <input id="password" name="password" type="password" class="form-control @error('password') is-invalid @enderror" autocomplete="new-password" required>
        @error('password')<div class="invalid-feedback">@foreach ($errors->get('password') as $passwordError)<div>{{ $passwordError }}</div>@endforeach</div>@enderror
        @include('auth.partials.password-rules')
    </div>
    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password" required>
    </div>
    <button class="btn btn-primary w-100">Save password</button>
</form>
<div class="text-center mt-3"><a href="{{ route('password.request') }}">Send a new code</a></div>
@endsection
