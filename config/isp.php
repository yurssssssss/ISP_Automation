<?php

return [

    /*
    | Billing rules. All counts are in days.
    |
    | invoice_lead_days – an invoice is issued this many days before the due date
    | reminder_days     – the "your bill is due" email goes out this many days before
    | grace_days        – extra days after the due date before service is cut.
    |                     The due date itself is always payable: 0 = cut at
    |                     midnight right after the due date ends.
    */
    'invoice_lead_days' => (int) env('ISP_INVOICE_LEAD_DAYS', 7),
    'reminder_days' => (int) env('ISP_REMINDER_DAYS', 3),
    'grace_days' => (int) env('ISP_GRACE_DAYS', 0),

    /*
    | How a suspended client is cut off:
    |
    | disable – disable their PPP secret / hotspot user and drop the session
    |           (no internet at all; they pay using mobile data)
    | profile – move them to the "suspended_profile" PPP profile and drop the
    |           session. Pair that profile with a walled garden on the router
    |           so they can still reach the portal and PayMongo to pay.
    */
    'suspend_mode' => env('ISP_SUSPEND_MODE', 'disable'),
    'suspended_profile' => env('ISP_SUSPENDED_PROFILE', 'suspended'),

    /*
    | The first admin login, created by `php artisan db:seed`
    | (AdminUserSeeder). Locally, an empty password means Admin#12345.
    */
    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@isp.test'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    | Where the daily database backups land (the "backup" service in
    | compose.production.yaml). The dashboard shows the latest one; when the
    | folder doesn't exist (local development) it shows nothing.
    */
    'backup_path' => env('BACKUP_PATH', storage_path('backups')),

    /*
    | Office contact details shown on the public front page. Leave any of
    | them empty to hide that line.
    */
    'contact' => [
        'phone' => env('ISP_CONTACT_PHONE'),
        'email' => env('ISP_CONTACT_EMAIL'),
        'address' => env('ISP_CONTACT_ADDRESS'),
        'hours' => env('ISP_OFFICE_HOURS'),
    ],

    'mikrotik' => [
        'host' => env('MIKROTIK_HOST', '192.168.88.1'),
        // 8728 = API, 8729 = API-SSL (set MIKROTIK_SSL=true)
        'port' => (int) env('MIKROTIK_PORT', 8728),
        'ssl' => (bool) env('MIKROTIK_SSL', false),
        // RouterOS API-SSL normally uses a self-signed certificate.
        'ssl_verify' => (bool) env('MIKROTIK_SSL_VERIFY', false),
        'username' => env('MIKROTIK_USERNAME', 'admin'),
        'password' => env('MIKROTIK_PASSWORD', ''),
        'timeout' => (int) env('MIKROTIK_TIMEOUT', 10),
    ],

];
