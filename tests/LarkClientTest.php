<?php

namespace NotificationChannels\Lark\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;
use NotificationChannels\Lark\LarkClient;
use PHPUnit\Framework\TestCase;

class LarkClientTest extends TestCase
{
    private function mockClient(array $responses): LarkClient
    {
        $mock    = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $http    = new HttpClient(['handler' => $handler]);

        return new LarkClient($http, 'cli_test', 'sec_test');
    }

    private function tokenResponse(): Response
    {
        return new Response(200, [], json_encode([
            'tenant_access_token' => 'tok_abc123',
            'expire'              => 7200,
        ]));
    }

    private function errorResponse(int $code, string $msg): Response
    {
        return new Response(200, [], json_encode(['code' => $code, 'msg' => $msg]));
    }

    public function test_get_tenant_token_caches_result(): void
    {
        $client = $this->mockClient([$this->tokenResponse()]);

        $token1 = $client->getTenantToken();
        $token2 = $client->getTenantToken(); // served from cache

        $this->assertSame('tok_abc123', $token1);
        $this->assertSame($token1, $token2);
    }

    public function test_send_via_webhook_posts_to_url(): void
    {
        $capturedUrl = null;
        $mock        = new MockHandler([
            new Response(200, [], json_encode(['code' => 0])),
        ]);

        $handler = HandlerStack::create($mock);
        $handler->push(\GuzzleHttp\Middleware::mapRequest(function ($req) use (&$capturedUrl) {
            $capturedUrl = (string) $req->getUri();
            return $req;
        }));

        $client = new LarkClient(
            new HttpClient(['handler' => $handler]),
            '', ''
        );

        $client->sendViaWebhook('https://hook.example.com/abc', [
            'msg_type' => 'text',
            'content'  => ['text' => 'hello'],
        ]);

        $this->assertSame('https://hook.example.com/abc', $capturedUrl);
    }

    public function test_throws_on_lark_error_code(): void
    {
        $this->expectException(CouldNotSendNotification::class);

        $client = $this->mockClient([$this->errorResponse(99991672, 'token expired')]);
        $client->sendViaWebhook('https://hook.example.com', [
            'msg_type' => 'text',
            'content'  => ['text' => 'hi'],
        ]);
    }

    public function test_throws_missing_credentials_for_bot_api(): void
    {
        $this->expectException(CouldNotSendNotification::class);

        $http   = new HttpClient();
        $client = new LarkClient($http, '', ''); // no credentials
        $client->getTenantToken();
    }
}
