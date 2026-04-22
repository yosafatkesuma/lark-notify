<?php

namespace NotificationChannels\Lark;

use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

class LarkServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish config: php artisan vendor:publish --tag=lark-config
        $this->publishes([
            __DIR__ . '/../config/lark.php' => config_path('lark.php'),
        ], 'lark-config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/lark.php', 'lark');

        $this->app->singleton(LarkClient::class, function ($app) {
            return new LarkClient(
                http: new HttpClient(),
                appId: (string) config('lark.app_id', ''),
                appSecret: (string) config('lark.app_secret', ''),
                baseUri: (string) config('lark.base_uri', 'https://open.larksuite.com/open-apis'),
                timeout: (int) config('lark.timeout', 10),
            );
        });

        // Bind LarkChannel
        $this->app->singleton(LarkChannel::class, function ($app) {
            return new LarkChannel(
                $app->make(LarkClient::class),
                $app->make(Dispatcher::class),
            );
        });

        // Bind LarkUpdates
        $this->app->bind(LarkUpdates::class, function ($app) {
            return new LarkUpdates($app->make(LarkClient::class));
        });
    }
}
