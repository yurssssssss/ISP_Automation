@extends('layouts.app')

@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-sm-10 col-md-6 col-lg-4">
        <h1 class="h4 text-center mb-4">{{ config('app.name') }}</h1>
        <div class="card shadow-sm"><div class="card-body p-4">
            @yield('card')
        </div></div>
    </div>
</div>
@endsection
