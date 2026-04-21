<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lark Bot Credentials
    |--------------------------------------------------------------------------
    |
    | To send notifications via the Lark Bot API you need an App ID and
    | App Secret from the Lark Open Platform (https://open.larksuite.com/app).
    |
    | For simple webhook-only bots you only need LARK_WEBHOOK_URL.
    |
    */

    'app_id' => env('LARK_APP_ID'),

    'app_secret' => env('LARK_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Default Webhook URL
    |--------------------------------------------------------------------------
    |
    | Optional default webhook URL used when routeNotificationForLark()
    | returns null and no ->to() override is set on the message.
    |
    */

    'webhook_url' => env('LARK_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | Override this if you use a self-hosted Lark / Feishu server or a proxy.
    | Feishu (China): https://open.feishu.cn/open-apis
    | Lark  (Global): https://open.larksuite.com/open-apis
    |
    */

    'base_uri' => env('LARK_API_BASE_URI', 'https://open.larksuite.com/open-apis'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    */

    'timeout' => env('LARK_TIMEOUT', 10),

];
