<?php

namespace NotificationChannels\Lark\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Mockery;
use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;
use NotificationChannels\Lark\LarkChannel;
use NotificationChannels\Lark\LarkClient;
use NotificationChannels\Lark\LarkServiceProvider;
use NotificationChannels\Lark\Messages\LarkCard;
use NotificationChannels\Lark\Tests\Fixtures\TestNotifiable;
use NotificationChannels\Lark\Tests\Fixtures\TestNotification;
use Orchestra\Testbench\TestCase;

class LarkCardAttachmentsTest extends TestCase
{
    /** Smallest valid PNG (1x1 transparent pixel). */
    private const PNG_BYTES_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private array $tempFiles = [];

    protected function getPackageProviders($app): array
    {
        return [LarkServiceProvider::class];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        Mockery::close();

        parent::tearDown();
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    public function test_image_key_renders_img_element_leaving_display_options_to_lark(): void
    {
        $card = LarkCard::create('Report')->image('img_v2_abc');

        // mode / preview are omitted so Lark applies its defaults (crop_center, preview on)
        $this->assertSame([[
            'tag' => 'img',
            'img_key' => 'img_v2_abc',
            'alt' => ['tag' => 'plain_text', 'content' => ''],
        ]], $card->toArray()['content']['elements']);
    }

    public function test_attach_image_data_detects_keys_without_img_prefix(): void
    {
        set_error_handler(fn () => true, E_USER_DEPRECATED);

        try {
            $card = LarkCard::create('Legacy')->attachImageData('v4_future-key_123', 'chart.png');
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($card->needsUpload());
        $this->assertSame('v4_future-key_123', $card->toArray()['content']['elements'][0]['img_key']);
    }

    public function test_image_caption_mode_and_preview_are_rendered(): void
    {
        $card = LarkCard::create('Report')
            ->image('img_v2_abc', 'Architecture diagram', 'fit_horizontal', false);

        $elements = $card->toArray()['content']['elements'];

        $this->assertSame('div', $elements[0]['tag']);
        $this->assertSame('Architecture diagram', $elements[0]['text']['content']);
        $this->assertSame('img_v2_abc', $elements[1]['img_key']);
        $this->assertSame('Architecture diagram', $elements[1]['alt']['content']);
        $this->assertSame('fit_horizontal', $elements[1]['mode']);
        $this->assertFalse($elements[1]['preview']);
    }

    public function test_images_render_after_body_and_before_buttons(): void
    {
        $card = LarkCard::create('Report')
            ->content('Body')
            ->image('img_v2_abc')
            ->button('Open', 'https://example.com');

        $tags = array_column($card->toArray()['content']['elements'], 'tag');

        $this->assertSame(['div', 'img', 'action'], $tags);
    }

    public function test_attach_image_data_with_image_key_still_works_but_is_deprecated(): void
    {
        $deprecations = [];
        set_error_handler(function (int $errno, string $msg) use (&$deprecations) {
            $deprecations[] = $msg;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $card = LarkCard::create('Legacy')
                ->attachImageData('img_v3_02ab-cd_ef', 'chart.png', null, 'stretch', false);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('image($imageKey)', $deprecations[0]);
        $this->assertFalse($card->needsUpload());

        $img = $card->toArray()['content']['elements'][0];
        $this->assertSame('img_v3_02ab-cd_ef', $img['img_key']);
        $this->assertSame('stretch', $img['mode']);
        $this->assertFalse($img['preview']);
    }

    public function test_attach_image_data_with_bytes_is_queued_for_upload(): void
    {
        $card = LarkCard::create('Chart')->attachImageData($this->pngBytes(), 'chart.png');

        $this->assertTrue($card->needsUpload());
    }

    public function test_to_array_throws_when_image_is_not_uploaded_yet(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        $this->expectExceptionMessage('has not been uploaded yet');

        LarkCard::create('Chart')->attachImage('/tmp/chart.png')->toArray();
    }

    public function test_non_image_attachments_throw_a_clear_error(): void
    {
        foreach (['file', 'audio', 'video'] as $method) {
            try {
                LarkCard::create('Report')->{$method}('file_v2_abc')->toArray();
                $this->fail("{$method}() on a card should throw");
            } catch (CouldNotSendNotification $e) {
                $this->assertStringContainsString("not {$method} attachments", $e->getMessage());
                $this->assertStringContainsString('LarkFile', $e->getMessage());
            }
        }
    }

    // ── Sending through LarkChannel ───────────────────────────────────────────

    public function test_channel_uploads_local_image_before_sending(): void
    {
        $path = $this->tempPng();

        $channel = $this->channel([
            $this->larkResponse(['tenant_access_token' => 'tok', 'expire' => 7200]),
            $this->larkResponse(['code' => 0, 'data' => ['image_key' => 'img_v2_uploaded']]),
            $this->larkResponse(['code' => 0, 'msg' => 'success']),
        ]);

        $card = LarkCard::create('Chart')->attachImage($path, 'Daily chart');
        $channel->send(new TestNotifiable(), new TestNotification($card));

        $this->assertCount(3, $this->history);
        $this->assertStringEndsWith('/im/v1/images', (string) $this->history[1]['request']->getUri());

        $elements = $this->sentCardElements();
        $this->assertSame('Daily chart', $elements[0]['text']['content']);
        $this->assertSame('img_v2_uploaded', $elements[1]['img_key']);
    }

    public function test_channel_uploads_raw_image_bytes_before_sending(): void
    {
        $channel = $this->channel([
            $this->larkResponse(['tenant_access_token' => 'tok', 'expire' => 7200]),
            $this->larkResponse(['code' => 0, 'data' => ['image_key' => 'img_v2_from_bytes']]),
            $this->larkResponse(['code' => 0, 'msg' => 'success']),
        ]);

        $card = LarkCard::create('Chart')->attachImageData($this->pngBytes(), 'chart.png');
        $channel->send(new TestNotifiable(), new TestNotification($card));

        $this->assertSame('img_v2_from_bytes', $this->sentCardElements()[0]['img_key']);
    }

    public function test_channel_does_not_upload_when_card_has_only_image_keys(): void
    {
        $channel = $this->channel([
            $this->larkResponse(['tenant_access_token' => 'tok', 'expire' => 7200]),
            $this->larkResponse(['code' => 0, 'msg' => 'success']),
        ]);

        $card = LarkCard::create('Chart')->image('img_v2_existing');
        $channel->send(new TestNotifiable(), new TestNotification($card));

        $this->assertCount(2, $this->history);
        $this->assertSame('img_v2_existing', $this->sentCardElements()[0]['img_key']);
    }

    public function test_channel_rejects_non_image_attachment_without_uploading(): void
    {
        $path = $this->tempPng('report.pdf');

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->once()->with(Mockery::type(NotificationFailed::class));

        $channel = $this->channel([], $events);

        $this->expectException(CouldNotSendNotification::class);
        $this->expectExceptionMessage('can only embed images');

        try {
            $card = LarkCard::create('Report')->attachFile($path);
            $channel->send(new TestNotifiable(), new TestNotification($card));
        } finally {
            $this->assertCount(0, $this->history, 'No HTTP request should be made');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function channel(array $responses, ?Dispatcher $events = null): LarkChannel
    {
        $this->history = [];

        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->history));

        $client = new LarkClient(new HttpClient(['handler' => $handler]), 'cli_test', 'sec_test');

        return new LarkChannel($client, $events ?? Mockery::mock(Dispatcher::class));
    }

    private function larkResponse(array $body): Response
    {
        return new Response(200, [], json_encode($body));
    }

    /** Card elements from the last (message send) request. */
    private function sentCardElements(): array
    {
        $request = end($this->history)['request'];
        $this->assertStringContainsString('/im/v1/messages', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('interactive', $body['msg_type']);

        return json_decode($body['content'], true)['elements'];
    }

    private function pngBytes(): string
    {
        return base64_decode(self::PNG_BYTES_BASE64);
    }

    private function tempPng(string $name = 'chart.png'): string
    {
        $path = sys_get_temp_dir() . '/lark_test_' . uniqid() . '_' . $name;
        file_put_contents($path, $this->pngBytes());

        return $this->tempFiles[] = $path;
    }
}
