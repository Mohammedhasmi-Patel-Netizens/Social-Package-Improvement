<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Model Mapping
    |--------------------------------------------------------------------------
    |
    | Define the model classes used by your application for users, admins,
    | platforms, subscriptions, files, and post metrics. This allows the
    | package to remain independent of application-specific models.
    |
    */

    'models' => [
        'user' => \App\Models\User::class,
        'admin' => \App\Models\Admin::class,
        'platform' => \App\Models\MediaPlatform::class,
        'subscription' => \App\Models\Subscription::class,
        'file' => \App\Models\Core\File::class,
        'metric' => \App\Models\PostMetric::class,
    ],

    /*
     | Queue to dispatch PublishSocialPostJob on.
     | Set SOCIAL_POSTER_QUEUE=social in your .env for a dedicated worker.
     */
    'queue' => env('SOCIAL_POSTER_QUEUE', 'default'),

    /*
     | Default file storage path for post attachments.
     | Override in your app's config/social-poster.php after publishing.
     */
    'file_path' => env('SOCIAL_POSTER_FILE_PATH', 'assets/images/post'),
];
