<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * auto_suspend: false = never cut automatically for an unpaid bill (the
     * admin decides). suspension_hold_until: an admin restored the account
     * while a bill was still unpaid; automation leaves it on through this date.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_suspend')->default(true)->after('status');
            $table->date('suspension_hold_until')->nullable()->after('auto_suspend');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['auto_suspend', 'suspension_hold_until']);
        });
    }
};
