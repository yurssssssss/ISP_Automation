<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/plus-jakarta-sans@5.1.1/index.min.css" integrity="sha384-jTc/GQ1YGohJnajQ/NeAhEpgk2WtIQtwCO7hymKvteimOc1G9Aj50WfpXpkhphdA" crossorigin="anonymous">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body class="site auth-page">
<header class="auth-header">
    <div class="container d-flex justify-content-between align-items-center">
        <a class="brand" href="{{ route('landing') }}">
            <span class="logo-mark">@include('partials.icon', ['name' => 'wifi'])</span>{{ config('app.name') }}
        </a>
        <a class="back d-inline-flex align-items-center gap-1" href="{{ route('landing') }}">
            @include('partials.icon', ['name' => 'home', 'class' => 'd-none d-sm-inline']) <span>Back to home</span>
        </a>
    </div>
</header>

<main class="auth-main">
    <div class="container d-flex justify-content-center">
        <div class="auth-card">
            @if (session('status'))<div class="alert alert-success small rounded-4">{{ session('status') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger small rounded-4">{{ session('error') }}</div>@endif
            @yield('card')
        </div>
    </div>
</main>

<footer class="auth-footer">
    <div class="container d-flex flex-wrap justify-content-between gap-2">
        <span>© {{ now()->year }} {{ config('app.name') }}</span>
        <a href="{{ route('landing') }}#contact">Need help? Contact us</a>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
