<?php

namespace NotificationChannels\Lark\Tests\Fixtures;

use Illuminate\Notifications\Notification;
use NotificationChannels\Lark\Messages\LarkMessage;

class TestNotification extends Notification
{
    public function __construct(private readonly LarkMessage $message) {}

    public function toLark($notifiable): LarkMessage
    {
        return $this->message;
    }
}
