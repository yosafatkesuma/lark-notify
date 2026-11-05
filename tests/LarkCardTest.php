<?php

namespace NotificationChannels\Lark\Tests;

use NotificationChannels\Lark\Messages\LarkCard;
use PHPUnit\Framework\TestCase;

class LarkCardTest extends TestCase
{
    public function test_create_sets_title(): void
    {
        $payload = LarkCard::create('My Title')->toArray();
        $this->assertSame('My Title', $payload['content']['header']['title']['content']);
    }

    public function test_color_sets_template(): void
    {
        $payload = LarkCard::create('T')->color('red')->toArray();
        $this->assertSame('red', $payload['content']['header']['template']);
    }

    public function test_field_adds_two_column_entry(): void
    {
        $payload = LarkCard::create('T')->field('Status', 'Shipped')->toArray();

        $div = collect($payload['content']['elements'])
            ->firstWhere('tag', 'div');

        $this->assertStringContainsString('Status', $div['fields'][0]['text']['content']);
        $this->assertStringContainsString('Shipped', $div['fields'][0]['text']['content']);
        $this->assertTrue($div['fields'][0]['is_short']);
    }

    public function test_line_if_works(): void
    {
        $card = LarkCard::create('T')
            ->lineIf(false, 'no')
            ->lineIf(true, 'yes');

        $payload = $card->toArray();
        $div     = collect($payload['content']['elements'])->firstWhere('tag', 'div');
        $this->assertSame('yes', $div['text']['content']);
    }

    public function test_button_appends_action(): void
    {
        $payload = LarkCard::create('T')
            ->button('Go', 'https://x.com', 'primary')
            ->toArray();

        $action = collect($payload['content']['elements'])
            ->firstWhere('tag', 'action');

        $this->assertSame('https://x.com', $action['actions'][0]['url']);
        $this->assertSame('primary', $action['actions'][0]['type']);
    }

    public function test_msg_type_is_interactive(): void
    {
        $this->assertSame('interactive', LarkCard::create('T')->toArray()['msg_type']);
    }
}
