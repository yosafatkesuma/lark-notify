<?php

namespace NotificationChannels\Lark;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

class LarkUpdates
{
    protected int $limit = 20;

    protected ?string $pageToken = null;

    protected array $options = [];

    public function __construct(protected LarkClient $client) {}

    /**
     * Mirrors: TelegramUpdates::create()
     *
     * @example
     * LarkUpdates::create()->limit(5)->get();
     */
    public static function create(): static
    {
        return app(static::class);
    }

    /**
     * Limit the number of events returned.
     * Mirrors: ->limit(2)
     */
    public function limit(int $n): static
    {
        $this->limit = $n;

        return $this;
    }

    /**
     * Pagination cursor.
     * Mirrors: ->latest() (simplified)
     */
    public function pageToken(string $token): static
    {
        $this->pageToken = $token;

        return $this;
    }

    /**
     * Merge extra options.
     * Mirrors: ->options(['timeout' => 0])
     */
    public function options(array $opts): static
    {
        $this->options = array_merge($this->options, $opts);

        return $this;
    }

    /**
     * Fetch events/updates.
     * Mirrors: ->get()
     *
     * Returns ['ok' => bool, 'events' => [...], 'nextPageToken' => ?string]
     */
    public function get(): array
    {
        $token = $this->client->getTenantToken();
        $base  = config('lark.base_uri', 'https://open.larksuite.com/open-apis');

        $query = http_build_query(array_filter([
            'page_size'  => $this->limit,
            'page_token' => $this->pageToken,
        ]));

        try {
            $response = app(\GuzzleHttp\Client::class)->get(
                "{$base}/application/v6/applications/event?{$query}",
                ['headers' => ['Authorization' => "Bearer {$token}"]]
            );

            $data = json_decode((string) $response->getBody(), true);

            return [
                'ok'            => ($data['code'] ?? -1) === 0,
                'events'        => $data['data']['items'] ?? [],
                'nextPageToken' => $data['data']['page_token'] ?? null,
            ];

        } catch (\Exception $e) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark($e->getMessage());
        }
    }
}
