<?php

namespace NotificationChannels\Lark\Facades;

use Illuminate\Support\Facades\Facade;
use NotificationChannels\Lark\LarkClient;

/**
 * @method static array sendViaWebhook(string $webhookUrl, array $payload)
 * @method static array sendViaBot(string $receiveId, string $receiveIdType, array $payload)
 * @method static string getTenantToken()
 *
 * @see \NotificationChannels\Lark\LarkClient
 */
class Lark extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LarkClient::class;
    }
}
