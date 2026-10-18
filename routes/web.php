<?php

use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\EmailCheckController;
use App\Http\Controllers\PayMongoWebhookController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/home');

// PayMongo → us (signature-checked; CSRF-exempt in bootstrap/app.php).
Route::post('/webhooks/paymongo', PayMongoWebhookController::class)->middleware('throttle:120,1')->name('webhooks.paymongo');

// Live email check on forms (guests on login/reset, admins adding a customer).
Route::post('/email-check', EmailCheckController::class)->middleware('throttle:email-check')->name('email.check');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');

    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:otp-send')->name('password.email');
    Route::get('/reset-password', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:otp-check')->name('password.update');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/verify-email', [EmailVerificationController::class, 'show'])->name('verification.notice');
    Route::post('/verify-email', [EmailVerificationController::class, 'verify'])->middleware('throttle:otp-check')->name('verification.verify');
    Route::post('/verify-email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:otp-send')->name('verification.send');

    Route::middleware('email.verified')->group(function () {
        Route::get('/home', fn () => redirect()->route(auth()->user()->dashboardRoute()))->name('home');

        Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
            Route::post('/subscriptions/{subscription}/suspend', [DashboardController::class, 'suspend'])->name('subscriptions.suspend');
            Route::post('/subscriptions/{subscription}/restore', [DashboardController::class, 'restore'])->name('subscriptions.restore');
            Route::post('/invoices/{invoice}/cash', [DashboardController::class, 'recordCash'])->name('invoices.cash');
            Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
            Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
            Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
            Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
        });

        Route::middleware('role:client')->prefix('portal')->name('portal.')->group(function () {
            Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
            Route::post('/invoices/{invoice}/pay', [PortalController::class, 'pay'])->middleware('throttle:pay')->name('invoices.pay');
            Route::get('/invoices/{invoice}/paid', [PortalController::class, 'returned'])->name('invoices.return');
        });
    });
});
