<?php

namespace NotificationChannels\Lark\Facades;

use Illuminate\Support\Facades\Facade;
use NotificationChannels\Lark\LarkClient;

/**
 * @method static array sendViaWebhook(string $webhookUrl, array $payload)
 * @method static array sendViaBot(string $receiveId, string $receiveIdType, array $payload)
 * @method static string getTenantToken()
 * @method static string uploadImage(string $path, string $imageType = 'message')
 * @method static string uploadFile(string $path, ?string $fileType = null)
 * @method static string uploadFromRequest(object $uploadedFile)
 * @method static \NotificationChannels\Lark\LarkUploader uploader()
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
