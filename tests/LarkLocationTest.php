<?php

namespace NotificationChannels\Lark\Tests;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;
use NotificationChannels\Lark\Messages\LarkLocation;
use PHPUnit\Framework\TestCase;

class LarkLocationTest extends TestCase
{
    public function test_builds_location_payload(): void
    {
        $payload = LarkLocation::create()
            ->latitude(1.3521)
            ->longitude(103.8198)
            ->toArray();

        $this->assertSame(1.3521, $payload['_meta']['latitude']);
        $this->assertSame(103.8198, $payload['_meta']['longitude']);
    }

    public function test_throws_without_latitude(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        LarkLocation::create()->longitude(103.8198)->toArray();
    }

    public function test_throws_without_longitude(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        LarkLocation::create()->latitude(1.3521)->toArray();
    }
}
