<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-body-tertiary">
@auth
<nav class="navbar navbar-expand-md bg-dark" data-bs-theme="dark">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="{{ route('home') }}">{{ config('app.name') }}</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                @if (auth()->user()->isAdmin() && auth()->user()->hasVerifiedEmail())
                    <li class="nav-item"><a class="nav-link" href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('admin.clients.create') }}">Add client</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('admin.plans.index') }}">Plans</a></li>
                @endif
            </ul>
            <span class="navbar-text me-3 small">
                <span class="badge text-bg-{{ auth()->user()->isAdmin() ? 'warning' : 'info' }} me-1">{{ auth()->user()->isAdmin() ? 'Admin' : 'Client' }}</span>
                {{ auth()->user()->email }}
            </span>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-light btn-sm">Sign out</button></form>
        </div>
    </div>
</nav>
@endauth

<main class="container py-4">
    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
