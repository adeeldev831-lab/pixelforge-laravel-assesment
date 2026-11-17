<?php

return [

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Users pay Pixelforge's cost plus this percentage.
    'markup_percent' => 25,

    // Comma-separated emails of the operators who may open the admin report.
    'admin_emails' => array_filter(array_map('trim', explode(',', env('ADMIN_EMAILS', '')))),

];
