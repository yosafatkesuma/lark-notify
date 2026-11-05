<?php

namespace NotificationChannels\Lark\Tests;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;
use NotificationChannels\Lark\Messages\LarkFile;
use PHPUnit\Framework\TestCase;

class LarkFileTest extends TestCase
{
    public function test_image_sets_type_and_key(): void
    {
        $payload = LarkFile::create()->image('img_key_123')->toArray();
        $this->assertSame('image', $payload['msg_type']);
        $this->assertSame('img_key_123', $payload['content']['image_key']);
    }

    public function test_file_sets_type_and_key(): void
    {
        $payload = LarkFile::create()->file('file_key_abc')->toArray();
        $this->assertSame('file', $payload['msg_type']);
        $this->assertSame('file_key_abc', $payload['content']['file_key']);
    }

    public function test_audio_sets_type(): void
    {
        $payload = LarkFile::create()->audio('audio_key_xyz')->toArray();
        $this->assertSame('audio', $payload['msg_type']);
    }

    public function test_video_sets_type(): void
    {
        $payload = LarkFile::create()->video('video_key_xyz')->toArray();
        $this->assertSame('media', $payload['msg_type']);
    }

    public function test_content_sets_caption(): void
    {
        $payload = LarkFile::create()->image('img_key')->content('Caption')->toArray();
        $this->assertSame('Caption', $payload['content']['text']);
    }

    public function test_throws_without_file_key(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        LarkFile::create()->toArray();
    }
}
