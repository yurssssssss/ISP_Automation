@extends('layouts.guest')
@section('title', 'Verify your email')

@section('card')
<h2 class="h5 mb-2">Verify your email</h2>
<p class="text-body-secondary small">Enter the code we sent to <strong>{{ $email }}</strong>. It expires in {{ \App\Support\EmailCode::MINUTES }} minutes.</p>

<form method="POST" action="{{ route('verification.verify') }}">
    @csrf
    @include('auth.partials.code-input')
    <button class="btn btn-primary w-100">Verify</button>
</form>

<form method="POST" action="{{ route('verification.send') }}" class="text-center mt-3">
    @csrf
    <button class="btn btn-link btn-sm" @disabled($resendIn > 0)>
        {{ $resendIn > 0 ? "Resend available in {$resendIn}s (refresh the page)" : 'Send a new code' }}
    </button>
</form>
@endsection
