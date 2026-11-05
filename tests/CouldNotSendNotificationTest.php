<?php

namespace NotificationChannels\Lark\Tests;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;
use PHPUnit\Framework\TestCase;

class CouldNotSendNotificationTest extends TestCase
{
    public function test_service_responded_with_an_error(): void
    {
        $e = CouldNotSendNotification::serviceRespondedWithAnError(500, 'Internal Server Error');
        $this->assertStringContainsString('500', $e->getMessage());
        $this->assertInstanceOf(\Exception::class, $e);
    }

    public function test_lark_responded_with_an_error(): void
    {
        $e = CouldNotSendNotification::larkRespondedWithAnError(99991672, 'token expired');
        $this->assertStringContainsString('99991672', $e->getMessage());
    }

    public function test_missing_credentials(): void
    {
        $e = CouldNotSendNotification::missingCredentials();
        $this->assertStringContainsString('app_id', $e->getMessage());
    }

    public function test_no_recipient(): void
    {
        $e = CouldNotSendNotification::noRecipient();
        $this->assertStringContainsString('recipient', $e->getMessage());
    }
}
