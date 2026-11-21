<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret'   => env('RECAPTCHA_SECRET_KEY'),
    ],

    // Поиск по комментариям (App\Services\CommentSearch). Без Elasticsearch страница
    // работает, только поиск отвечает 503. В тестах выключен (phpunit.xml),
    // тесты поиска включают его сами и подменяют ответы через Http::fake().
    'elasticsearch' => [
        'enabled' => (bool) env('ELASTICSEARCH_ENABLED', true),
        'host' => env('ELASTICSEARCH_HOST', 'http://elasticsearch:9200'),
        'index' => env('ELASTICSEARCH_INDEX', 'comments'),
        'timeout' => 3,
    ],

];
