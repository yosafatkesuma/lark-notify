<?php

namespace NotificationChannels\Lark;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Contracts\Events\Dispatcher;
use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;
use NotificationChannels\Lark\Messages\LarkMessage;
use NotificationChannels\Lark\Messages\LarkCard;
use NotificationChannels\Lark\Messages\LarkFile;
use NotificationChannels\Lark\Messages\LarkLocation;

class LarkChannel
{
    public function __construct(
        protected LarkClient $lark,
        protected Dispatcher $events,
    ) {}

    /**
     * Send the notification.
     *
     * Called automatically by Laravel's notification system.
     * Mirrors the send() method in laravel-notification-channels/telegram.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        try {
            /** @var LarkMessage|LarkCard|LarkFile|LarkLocation $message */
            $message = $notification->toLark($notifiable);

            // Auto-upload file/image if a local path or UploadedFile was set
            if ($message instanceof LarkFile && $message->needsUpload()) {
                $message->performUpload($this->lark->uploader());
            }

            // ->to() on the message takes priority over routeNotificationForLark()
            $chatId = $message->getChatId()
                ?? $notifiable->routeNotificationFor('lark', $notification);

            // Last resort: use default webhook from config
            $webhookUrl = config('lark.webhook_url');

            if (! $chatId && ! $webhookUrl) {
                throw CouldNotSendNotification::noRecipient();
            }

            $payload = $message->toArray();

            if ($chatId) {
                $this->lark->sendViaBot($chatId, $message->getChatIdType(), $payload);
            } else {
                $this->lark->sendViaWebhook($webhookUrl, $payload);
            }

        } catch (CouldNotSendNotification $e) {
            $this->events->dispatch(
                new NotificationFailed($notifiable, $notification, 'lark', [
                    'message'   => $e->getMessage(),
                    'exception' => $e,
                ])
            );

            throw $e;
        }
    }
}
