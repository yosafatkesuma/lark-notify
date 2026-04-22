<?php

namespace NotificationChannels\Lark\Exceptions;

use Exception;

class CouldNotSendNotification extends Exception
{
    public static function serviceRespondedWithAnError(int $status, string $body): self
    {
        return new self("Lark responded with HTTP {$status}: {$body}");
    }

    public static function larkRespondedWithAnError(int $code, string $message): self
    {
        return new self("Lark API error (code {$code}): {$message}");
    }

    public static function couldNotCommunicateWithLark(string $reason): self
    {
        return new self("Could not communicate with Lark: {$reason}");
    }

    public static function missingCredentials(): self
    {
        return new self(
            'Lark app_id and app_secret are required for Bot API calls. ' .
            'Set LARK_APP_ID and LARK_APP_SECRET in your .env file.',
        );
    }

    public static function noRecipient(): self
    {
        return new self(
            'No recipient specified. Either call ->to() on the message or ' .
            'implement routeNotificationForLark() on the notifiable.',
        );
    }
}
