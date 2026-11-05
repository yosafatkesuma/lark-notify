<?php

namespace NotificationChannels\Lark\Tests\Fixtures;

class TestNotifiable
{
    public string $larkOpenId = 'ou_test_123';

    public function routeNotificationFor(string $channel, $notification = null): ?string
    {
        return $channel === 'lark' ? $this->larkOpenId : null;
    }
}
