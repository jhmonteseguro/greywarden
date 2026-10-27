<?php

/**
 * Application parameters. Business rules that may need tuning live here so
 * they are configured in ONE place (see helpers/RiskMatrix, DevicePosture...).
 */
return [
    'appName' => gw_env('APP_NAME', 'Greywarden'),
    'pageSize' => 25,

    // Risk rating bands (score = likelihood x impact, 1..25). Evaluated in order;
    // the first band whose `max` >= score wins.
    'risk.ratingBands' => [
        ['key' => 'low', 'label' => 'Low', 'max' => 4],
        ['key' => 'medium', 'label' => 'Medium', 'max' => 9],
        ['key' => 'high', 'label' => 'High', 'max' => 16],
        ['key' => 'critical', 'label' => 'Critical', 'max' => 25],
    ],

    // Incident: regulator notification window (GDPR Art. 33 = 72h from awareness)
    'incident.regulatorDeadlineHours' => 72,

    // Device posture
    'device.patchMaxAgeDays' => 30,
    'device.warrantyWarningDays' => 60,
    'device.staleDays' => 30,           // synced devices not seen for longer are flagged

    // Control Register: warn this many days before a control's next test is due
    'control.testDueSoonDays' => 14,

    // Branding (Admin › Settings, components/Branding): the uploaded logo is stored here,
    // outside the web root (bind-mounted ./storage in Docker)
    'branding.path' => gw_env('BRANDING_PATH', '/var/www/storage/branding'),

    // Attachments: stored outside the web root (bind-mounted ./storage in Docker).
    // Keep maxSizeMb within PHP's upload_max_filesize and nginx's client_max_body_size.
    'attachments.path' => gw_env('ATTACHMENTS_PATH', '/var/www/storage/attachments'),
    'attachments.maxSizeMb' => 20,

    // Authentication hardening
    'auth.passwordMinLength' => 10,
    'auth.maxFailedAttempts' => 5,      // per username
    'auth.ipMaxFailedAttempts' => 20,   // per source IP
    'auth.attemptWindow' => 900,        // seconds
    'auth.lockoutDuration' => 900,      // seconds

    // Dashboard
    'dashboard.attentionLimit' => 6,
    'dashboard.activityLimit' => 12,
];
