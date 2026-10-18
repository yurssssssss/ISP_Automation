<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Home internet plans from {{ config('app.name') }}. Pay your bill online and manage your account anytime.">
    <title>{{ config('app.name') }} · Home internet</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource-variable/plus-jakarta-sans@5.1.1/index.min.css">
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body class="site">
<a class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-light z-3" href="#main">Skip to content</a>

@if (isset($contact['phone']) || isset($contact['email']))
    <div class="topbar">
        <div class="container d-flex flex-wrap justify-content-between gap-2">
            <span>Customer portal open 24/7</span>
            <span class="d-flex gap-3">
                @isset($contact['phone'])<a href="tel:{{ preg_replace('/[^\d+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>@endisset
                @isset($contact['email'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@endisset
            </span>
        </div>
    </div>
@endif

<nav class="navbar navbar-expand-lg site-nav sticky-top" aria-label="Main">
    <div class="container">
        <a class="navbar-brand" href="{{ route('landing') }}">
            <span class="logo-mark">@include('partials.icon', ['name' => 'wifi'])</span>
            {{ config('app.name') }}
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#siteNav" aria-controls="siteNav" aria-expanded="false" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="siteNav">
            <ul class="navbar-nav mx-auto">
                <li class="nav-item"><a class="nav-link" href="#plans">Plans</a></li>
                <li class="nav-item"><a class="nav-link" href="#why">Why us</a></li>
                <li class="nav-item"><a class="nav-link" href="#how">Get connected</a></li>
                <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
            </ul>
            <div class="d-flex flex-column flex-lg-row gap-2">
                @auth
                    <a class="btn btn-primary btn-sm" href="{{ route('home') }}">My account</a>
                @else
                    <a class="btn btn-light-outline btn-sm" href="{{ route('login') }}">Sign in</a>
                    <a class="btn btn-primary btn-sm" href="#contact">Get connected</a>
                @endauth
            </div>
        </div>
    </div>
</nav>

<main id="main">
    <header class="hero">
        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <span class="pill"><span class="pill-dot"></span>Home internet plans</span>
                    <h1 class="hero-title">Fast, reliable internet <span class="text-brand">made simple.</span></h1>
                    <p class="hero-lead">Simple monthly plans, bills you can pay from your phone, and a connection that comes back on by itself the moment you've paid.</p>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <a href="#plans" class="btn btn-primary btn-lg">See plans</a>
                        <a href="{{ auth()->check() ? route('home') : route('login') }}" class="btn btn-light-outline btn-lg">Pay my bill</a>
                    </div>
                    <div class="hero-chips">
                        <span class="hero-chip">@include('partials.icon', ['name' => 'phone']){{ $paymentMethods->isNotEmpty() ? 'Pay online' : 'Pay at our office' }}</span>
                        <span class="hero-chip">@include('partials.icon', ['name' => 'refresh'])Auto reconnect</span>
                        <span class="hero-chip">@include('partials.icon', ['name' => 'bell'])Email reminders</span>
                    </div>
                </div>
                @if ($plans->isNotEmpty())
                    @php($samplePlan = $plans->first())
                    <div class="col-lg-5">
                        <div class="glance">
                            <div class="glance-head">
                                <h2>Your account at a glance</h2>
                                <span class="glance-tag">Preview</span>
                            </div>
                            <div class="glance-plan">
                                <span class="icon-tile">@include('partials.icon', ['name' => 'zap'])</span>
                                <div><strong>{{ $samplePlan->name }}</strong><span>{{ $samplePlan->speed_mbps }} Mbps · ₱{{ number_format((float) $samplePlan->price, 2) }} a month</span></div>
                            </div>
                            <div class="glance-rows">
                                <div class="glance-row"><span>Connection</span><span class="status-ok">Connected</span></div>
                                <div class="glance-row"><span>Next bill</span><strong>Emailed {{ $invoiceLeadDays }} {{ Str::plural('day', $invoiceLeadDays) }} before due</strong></div>
                                <div class="glance-row"><span>Speeds up to</span><strong>{{ $plans->max('speed_mbps') }} Mbps</strong></div>
                            </div>
                            <a href="{{ auth()->check() ? route('home') : route('login') }}" class="btn btn-primary w-100">{{ auth()->check() ? 'Open my account' : 'Sign in to see yours' }}</a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </header>

    <section id="plans" class="section">
        <div class="container">
            <div class="section-head">
                <span class="pill">Transparent pricing</span>
                <h2>Plans for every home</h2>
                <p>One flat price every month. Pick the speed that fits how you use the internet.</p>
            </div>

            @if ($plans->isEmpty())
                <p class="text-center text-body-secondary">Our plans will be posted here soon. <a href="#contact">Contact us</a> in the meantime.</p>
            @else
                <div class="row g-4 justify-content-center pt-2">
                    @foreach ($plans as $plan)
                        @php($featured = $plan->id === $fastestPlanId)
                        <div class="col-md-6 col-lg-4">
                            <article @class(['plan-card', 'is-featured' => $featured])>
                                @if ($featured)<span class="plan-ribbon">Fastest</span>@endif
                                <div class="plan-top">
                                    <h3 class="plan-name">{{ $plan->name }}</h3>
                                    <span class="icon-tile">@include('partials.icon', ['name' => $featured ? 'zap' : 'wifi'])</span>
                                </div>
                                <div class="plan-speed">{{ $plan->speed_mbps }}<small>Mbps</small></div>
                                <div class="plan-price">₱{{ number_format((float) $plan->price, 2) }} <span>/ month</span></div>
                                <ul class="plan-list">
                                    <li>@include('partials.icon', ['name' => 'check'])Billed monthly, same day each month</li>
                                    <li>@include('partials.icon', ['name' => 'check'])Email reminder before every due date</li>
                                    <li>@include('partials.icon', ['name' => 'check']){{ $paymentMethods->isNotEmpty() ? 'Pay online or at our office' : 'Pay at our office' }}</li>
                                </ul>
                                <a href="#contact" @class(['btn w-100 mt-auto', 'btn-primary' => $featured, 'btn-outline-primary' => ! $featured])>Get this plan</a>
                            </article>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section id="why" class="section section-soft">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <span class="pill">Why choose us</span>
                    <h2 class="fw-bold mt-3 mb-3" style="font-size: clamp(1.8rem, 3.5vw, 2.4rem);">Internet that's easy to live with</h2>
                    <p class="text-body-secondary mb-4">Less waiting in line, fewer surprises on your bill.</p>

                    <div class="feature-row">
                        <span class="icon-tile">@include('partials.icon', ['name' => 'phone'])</span>
                        <div>
                            <h3>Pay from your phone</h3>
                            @if ($paymentMethods->isNotEmpty())
                                <p>Settle your bill in a minute with {{ $paymentMethods->join(', ', ' or ') }} — or pay in cash at our office.</p>
                            @else
                                <p>Pay in cash at our office. Your payments and balance show up in your online account.</p>
                            @endif
                        </div>
                    </div>
                    <div class="feature-row">
                        <span class="icon-tile">@include('partials.icon', ['name' => 'refresh'])</span>
                        <div>
                            <h3>Back online by itself</h3>
                            <p>Missed a due date? As soon as your bill is paid, your connection is turned back on automatically — no call, no waiting for a technician.</p>
                        </div>
                    </div>
                    <div class="feature-row">
                        <span class="icon-tile">@include('partials.icon', ['name' => 'file'])</span>
                        <div>
                            <h3>Your account, anytime</h3>
                            <p>Sign in to see your plan, connection status, every bill and every payment in one place.</p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="timeline-card">
                        <span class="pill">How billing works</span>
                        <h3 class="mt-3 mb-0">No surprises, every month</h3>
                        <ol class="timeline">
                            <li>
                                <div class="timeline-when">{{ $invoiceLeadDays }} {{ Str::plural('day', $invoiceLeadDays) }} before</div>
                                <div class="timeline-what">Your bill arrives by email</div>
                            </li>
                            <li>
                                <div class="timeline-when">{{ $reminderDays }} {{ Str::plural('day', $reminderDays) }} before</div>
                                <div class="timeline-what">A friendly reminder</div>
                            </li>
                            {{-- The due date itself is payable; service pauses at midnight after it (plus any grace days). --}}
                            <li>
                                <div class="timeline-when">By the due date</div>
                                <div class="timeline-what">{{ $paymentMethods->isNotEmpty() ? 'Pay online or at our office' : 'Pay at our office' }}</div>
                            </li>
                            <li>
                                <div class="timeline-when">{{ $graceDays > 0 ? 'After a '.$graceDays.'-day grace period' : 'Day after the due date' }}</div>
                                <div class="timeline-what">Service pauses if still unpaid</div>
                            </li>
                            <li class="is-good">
                                <div class="timeline-when">Once paid</div>
                                <div class="timeline-what">Back online automatically</div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="how" class="section">
        <div class="container">
            <div class="section-head">
                <span class="pill">Getting connected</span>
                <h2>Three steps to get online</h2>
                <p>From your first message to your first bill.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="step-card">
                        <span class="step-num">1</span>
                        <h3>Reach out</h3>
                        <p>Tell us your address and the plan you want. We'll check that we can reach you and schedule an installation.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step-card">
                        <span class="step-num">2</span>
                        <h3>We set you up</h3>
                        <p>Our team installs your connection and creates your account using the email address you give us.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step-card">
                        <span class="step-num">3</span>
                        <h3>Set your password</h3>
                        <p>Use <a href="{{ route('password.request') }}">Forgot password</a> with that email to choose your password, then sign in to view and pay your bills.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="contact-band">
        <div class="container">
            <div class="contact-card">
                <div class="text-center mb-4">
                    <span class="pill">Get connected</span>
                    <h2 class="mt-3 mb-2">Ready to get connected?</h2>
                    <p class="text-body-secondary mb-0">Message or visit our office and we'll take it from there.</p>
                </div>
                @if ($contact)
                    <div class="row g-3">
                        @isset($contact['phone'])
                            <div class="col-sm-6"><a class="contact-item" href="tel:{{ preg_replace('/[^\d+]/', '', $contact['phone']) }}"><span class="icon-tile">@include('partials.icon', ['name' => 'call'])</span><span><small>Call or text</small><strong>{{ $contact['phone'] }}</strong></span></a></div>
                        @endisset
                        @isset($contact['email'])
                            <div class="col-sm-6"><a class="contact-item" href="mailto:{{ $contact['email'] }}"><span class="icon-tile">@include('partials.icon', ['name' => 'mail'])</span><span><small>Email</small><strong>{{ $contact['email'] }}</strong></span></a></div>
                        @endisset
                        @isset($contact['address'])
                            <div class="col-sm-6"><div class="contact-item"><span class="icon-tile">@include('partials.icon', ['name' => 'pin'])</span><span><small>Visit us</small><strong>{{ $contact['address'] }}</strong></span></div></div>
                        @endisset
                        @isset($contact['hours'])
                            <div class="col-sm-6"><div class="contact-item"><span class="icon-tile">@include('partials.icon', ['name' => 'clock'])</span><span><small>Office hours</small><strong>{{ $contact['hours'] }}</strong></span></div></div>
                        @endisset
                    </div>
                @else
                    <p class="text-center text-body-secondary mb-0">Our contact details will be posted here soon.</p>
                @endif
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-5">
                <a class="d-inline-flex align-items-center gap-2 fw-bold text-body text-decoration-none mb-3" href="{{ route('landing') }}">
                    <span class="logo-mark">@include('partials.icon', ['name' => 'wifi'])</span>{{ config('app.name') }}
                </a>
                <p class="text-body-secondary mb-0" style="max-width: 22rem;">Home internet with simple monthly plans and bills you can manage online.</p>
            </div>
            <div class="col-6 col-lg-3 offset-lg-1">
                <h3>Explore</h3>
                <ul>
                    <li><a href="#plans">Plans</a></li>
                    <li><a href="#why">Why us</a></li>
                    <li><a href="#how">Get connected</a></li>
                    <li><a href="#contact">Contact</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-3">
                <h3>Customers</h3>
                <ul>
                    @auth
                        <li><a href="{{ route('home') }}">Go to my account</a></li>
                    @else
                        <li><a href="{{ route('login') }}">Sign in</a></li>
                        <li><a href="{{ route('password.request') }}">Set or reset password</a></li>
                        <li><a href="{{ route('login') }}">Pay my bill</a></li>
                    @endauth
                </ul>
            </div>
        </div>
        <div class="site-footer-bottom">© {{ now()->year }} {{ config('app.name') }}. All rights reserved.</div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
