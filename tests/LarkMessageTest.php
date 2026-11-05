<?php

namespace NotificationChannels\Lark\Tests;

use NotificationChannels\Lark\Messages\LarkMessage;
use PHPUnit\Framework\TestCase;

class LarkMessageTest extends TestCase
{
    public function test_create_sets_content(): void
    {
        $msg = LarkMessage::create('Hello');
        $this->assertSame('Hello', $msg->getContent());
    }

    public function test_line_appends_with_newline(): void
    {
        $msg = LarkMessage::create('Hello')->line('World');
        $this->assertSame("Hello\nWorld", $msg->getContent());
    }

    public function test_line_if_respects_condition(): void
    {
        $msg = LarkMessage::create('Base')
            ->lineIf(false, 'hidden')
            ->lineIf(true, 'visible');
        $this->assertSame("Base\nvisible", $msg->getContent());
    }

    public function test_to_sets_chat_id_and_type(): void
    {
        $msg = LarkMessage::create()->to('ou_abc', 'open_id');
        $this->assertSame('ou_abc', $msg->getChatId());
        $this->assertSame('open_id', $msg->getChatIdType());
    }

    public function test_plain_text_payload(): void
    {
        $payload = LarkMessage::create('Hi')->toArray();
        $this->assertSame('text', $payload['msg_type']);
        $this->assertSame('Hi', $payload['content']['text']);
    }

    public function test_button_upgrades_to_interactive(): void
    {
        $payload = LarkMessage::create('Hi')
            ->button('Click', 'https://example.com')
            ->toArray();
        $this->assertSame('interactive', $payload['msg_type']);
    }

    public function test_button_sets_url_and_type(): void
    {
        $payload = LarkMessage::create('Hi')
            ->button('Go', 'https://a.com', 'primary')
            ->toArray();

        $action = collect($payload['content']['elements'])
            ->firstWhere('tag', 'action');

        $this->assertSame('https://a.com', $action['actions'][0]['url']);
        $this->assertSame('primary', $action['actions'][0]['type']);
    }

    public function test_button_with_callback_sets_value_not_url(): void
    {
        $payload = LarkMessage::create('Hi')
            ->buttonWithCallback('Confirm', ['action' => 'confirm'])
            ->toArray();

        $action = collect($payload['content']['elements'])
            ->firstWhere('tag', 'action');

        $this->assertSame(['action' => 'confirm'], $action['actions'][0]['value']);
        $this->assertArrayNotHasKey('url', $action['actions'][0]);
    }

    public function test_multiple_buttons(): void
    {
        $payload = LarkMessage::create('Hi')
            ->button('One', 'https://a.com')
            ->button('Two', 'https://b.com')
            ->toArray();

        $action = collect($payload['content']['elements'])
            ->firstWhere('tag', 'action');

        $this->assertCount(2, $action['actions']);
    }

    public function test_options_merges_extra_params(): void
    {
        $payload = LarkMessage::create('Hi')
            ->options(['custom_flag' => true])
            ->toArray();

        $this->assertTrue($payload['custom_flag']);
    }
}
