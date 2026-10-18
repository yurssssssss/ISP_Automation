<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · Admin · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/plus-jakarta-sans@5.1.1/index.min.css">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body class="site admin">
@php($admin = auth()->user())
<div class="admin-shell">
    <aside class="admin-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="adminSidebar" aria-label="Admin menu">
        <div class="offcanvas-body">
            <div class="d-flex justify-content-between align-items-start">
                <a class="admin-brand" href="{{ route('admin.dashboard') }}">
                    <span class="logo-mark">@include('partials.icon', ['name' => 'wifi'])</span>{{ config('app.name') }}
                </a>
                <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close menu"></button>
            </div>

            <p class="admin-nav-label">Manage</p>
            <ul class="admin-nav">
                <li><a href="{{ route('admin.dashboard') }}" @class(['active' => request()->routeIs('admin.dashboard')])>@include('partials.icon', ['name' => 'grid'])Dashboard</a></li>
                <li><a href="{{ route('admin.clients.create') }}" @class(['active' => request()->routeIs('admin.clients.*')])>@include('partials.icon', ['name' => 'user-plus'])Add client</a></li>
                <li><a href="{{ route('admin.plans.index') }}" @class(['active' => request()->routeIs('admin.plans.*')])>@include('partials.icon', ['name' => 'layers'])Plans</a></li>
            </ul>

            <p class="admin-nav-label">Website</p>
            <ul class="admin-nav">
                <li><a href="{{ route('landing') }}" target="_blank" rel="noopener">@include('partials.icon', ['name' => 'external'])View public site</a></li>
            </ul>

            <div class="admin-user">
                <div class="admin-user-row mb-3">
                    <span class="avatar">{{ Str::upper(Str::substr($admin->name, 0, 1)) }}</span>
                    <div style="min-width: 0;">
                        <div class="admin-user-email" title="{{ $admin->email }}">{{ $admin->email }}</div>
                        <span class="role-badge">Admin</span>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-light-outline btn-sm w-100 d-flex justify-content-center align-items-center gap-2">@include('partials.icon', ['name' => 'logout'])Sign out</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="admin-main">
        <div class="admin-topbar d-lg-none">
            <a class="admin-brand m-0 p-0" href="{{ route('admin.dashboard') }}">
                <span class="logo-mark">@include('partials.icon', ['name' => 'wifi'])</span>{{ config('app.name') }}
            </a>
            <button class="btn btn-light-outline btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open menu">
                @include('partials.icon', ['name' => 'menu'])
            </button>
        </div>

        <main class="admin-content">
            @if (session('status'))<div class="alert alert-success rounded-4 border-0">{{ session('status') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger rounded-4 border-0">{{ session('error') }}</div>@endif
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
