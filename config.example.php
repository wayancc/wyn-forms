<?php

/*
 * Copy to config.php to change server settings. Everything else
 * (notifications, SMTP, spam settings) is edited in the dashboard.
 */
return [
    // SQLite database file. Uploads are stored next to it in storage/uploads. Keep both outside public/.
    'database'    => 'storage/wyn-forms.sqlite',

    // Full address of this install, used for form URLs and links in notifications.
    'app_url'     => 'https://forms.example.com',

    // Path prefix when installed in a subfolder, e.g. '/forms/'.
    'base_url'    => '/',

    // Times in the dashboard and CSV exports, e.g. 'Asia/Makassar'.
    'timezone'    => 'UTC',

    // Behind Cloudflare or a reverse proxy? Set true so rate limits see the real visitor IP.
    'trust_proxy' => false,
];
