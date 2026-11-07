<?php

namespace NotificationChannels\Lark\Tests\Fixtures;

use Illuminate\Notifications\Notification;
use NotificationChannels\Lark\Messages\LarkCard;
use NotificationChannels\Lark\Messages\LarkFile;
use NotificationChannels\Lark\Messages\LarkLocation;
use NotificationChannels\Lark\Messages\LarkMessage;

class TestNotification extends Notification
{
    public function __construct(
        private readonly LarkMessage|LarkCard|LarkFile|LarkLocation $message,
    ) {}

    public function toLark($notifiable): LarkMessage|LarkCard|LarkFile|LarkLocation
    {
        return $this->message;
    }
}
