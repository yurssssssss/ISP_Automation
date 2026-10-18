<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core ISP billing tables:
 *
 *  clients        – the subscriber (optionally linked to a portal login)
 *  plans          – speed tiers, each mapped to a MikroTik PPP profile
 *  subscriptions  – a client on a plan, with the PPPoE/Hotspot login the
 *                   router knows them by and the next due date
 *  invoices       – one per billing period; can be paid in several payments
 *  payments       – PayMongo or cash; a gateway reference is unique, so a
 *                   repeated webhook can never record the same payment twice
 *  router_logs    – every enable/disable sent to the router, and its result
 *  webhook_events – PayMongo event ids already handled (idempotency)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('account_no', 20)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('active'); // active | suspended | inactive
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('speed_mbps');
            $table->decimal('price', 10, 2);
            $table->string('mikrotik_profile'); // /ppp/profile name on the router
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('service_type', 10)->default('pppoe'); // pppoe | hotspot
            $table->string('username')->unique();                 // PPP secret / hotspot user name
            $table->text('password')->nullable();                 // encrypted cast
            $table->date('next_due_date');
            $table->string('status', 20)->default('active');      // active | suspended | cancelled
            $table->dateTime('suspended_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_due_date']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->decimal('amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('status', 20)->default('unpaid'); // unpaid | partial | paid | void
            $table->string('checkout_session_id')->nullable()->index();
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'due_date']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 30);                  // gcash | maya | card | qrph | cash | ...
            $table->string('gateway', 20);                 // paymongo | manual
            $table->string('reference')->nullable()->unique();
            $table->decimal('gateway_fee', 10, 2)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at');
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('router_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('username');
            $table->string('action', 20); // enable | disable
            $table->boolean('success');
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id');
            $table->string('type');
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('email_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('purpose', 30); // verify_email | reset_password
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            // dateTime, not timestamp: on MariaDB the first timestamp column
            // silently resets to "now" on every update (e.g. counting a wrong
            // try), which would keep pushing the expiry back.
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->unique(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_codes');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('router_logs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('clients');
    }
};
