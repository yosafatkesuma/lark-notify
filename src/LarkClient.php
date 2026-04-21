<?php

namespace NotificationChannels\Lark;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

class LarkClient
{
    protected ?string $tenantToken = null;

    protected int $tokenExpiresAt = 0;

    public function __construct(
        protected HttpClient $http,
        protected string $appId = '',
        protected string $appSecret = '',
        protected string $baseUri = 'https://open.larksuite.com/open-apis',
        protected int $timeout = 10,
    ) {}

    // ── Webhook ───────────────────────────────────────────────────────────────

    /**
     * POST a payload to a custom bot webhook URL.
     * No credentials required.
     */
    public function sendViaWebhook(string $webhookUrl, array $payload): array
    {
        return $this->post($webhookUrl, $payload);
    }

    // ── Bot API ───────────────────────────────────────────────────────────────

    /**
     * Send via the Lark Bot API to a specific user / chat.
     */
    public function sendViaBot(string $receiveId, string $receiveIdType, array $payload): array
    {
        $token = $this->getTenantToken();

        $body = [
            'receive_id' => $receiveId,
            'msg_type'   => $payload['msg_type'],
            'content'    => json_encode($payload['content']),
        ];

        return $this->post(
            "{$this->baseUri}/im/v1/messages?receive_id_type={$receiveIdType}",
            $body,
            ['Authorization' => "Bearer {$token}"]
        );
    }

    // ── Tenant token (cached) ─────────────────────────────────────────────────

    /**
     * Obtain and cache the tenant_access_token (valid for ~2 hours).
     * Mirrors Laravel's cache() pattern — uses a simple in-memory cache.
     */
    public function getTenantToken(): string
    {
        if ($this->tenantToken && time() < $this->tokenExpiresAt) {
            return $this->tenantToken;
        }

        if (! $this->appId || ! $this->appSecret) {
            throw CouldNotSendNotification::missingCredentials();
        }

        $response = $this->post("{$this->baseUri}/auth/v3/tenant_access_token/internal", [
            'app_id'     => $this->appId,
            'app_secret' => $this->appSecret,
        ]);

        if (empty($response['tenant_access_token'])) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                'Failed to obtain tenant_access_token.'
            );
        }

        $this->tenantToken = $response['tenant_access_token'];
        $this->tokenExpiresAt = time() + ($response['expire'] ?? 7200) - 60;

        return $this->tenantToken;
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    protected function post(string $url, array $body, array $headers = []): array
    {
        try {
            $response = $this->http->post($url, [
                'json'    => $body,
                'timeout' => $this->timeout,
                'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            ]);

            $decoded = json_decode((string) $response->getBody(), true);

            if (isset($decoded['code']) && $decoded['code'] !== 0) {
                throw CouldNotSendNotification::larkRespondedWithAnError(
                    (int) $decoded['code'],
                    (string) ($decoded['msg'] ?? 'unknown')
                );
            }

            return $decoded;

        } catch (GuzzleException $e) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark($e->getMessage());
        }
    }
}
