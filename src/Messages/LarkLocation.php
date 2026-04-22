<?php

namespace NotificationChannels\Lark\Messages;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

class LarkLocation
{
    protected ?string $chatId = null;

    protected string $chatIdType = 'open_id';

    protected ?float $lat = null;

    protected ?float $lon = null;

    protected array $additionalParams = [];

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function create(): static
    {
        return new static();
    }

    // ── Routing ───────────────────────────────────────────────────────────────

    /** Mirrors: ->to($notifiable->lark_open_id) */
    public function to(string $chatId, string $type = 'open_id'): static
    {
        $this->chatId = $chatId;
        $this->chatIdType = $type;

        return $this;
    }

    // ── Coordinates ───────────────────────────────────────────────────────────

    /**
     * Set latitude.
     * Mirrors: ->latitude(40.7128)
     */
    public function latitude(float $lat): static
    {
        $this->lat = $lat;

        return $this;
    }

    /**
     * Set longitude.
     * Mirrors: ->longitude(-74.0060)
     */
    public function longitude(float $lon): static
    {
        $this->lon = $lon;

        return $this;
    }

    /** Merge additional raw API parameters. */
    public function options(array $params): static
    {
        $this->additionalParams = array_merge($this->additionalParams, $params);

        return $this;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getChatId(): ?string
    {
        return $this->chatId;
    }

    public function getChatIdType(): string
    {
        return $this->chatIdType;
    }

    // ── Serialise ─────────────────────────────────────────────────────────────

    public function toArray(): array
    {
        if ($this->lat === null || $this->lon === null) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                'LarkLocation requires both latitude() and longitude().',
            );
        }

        return array_merge([
            'msg_type' => 'text',
            'content' => [
                'text' => "📍 Location\nLatitude: {$this->lat}\nLongitude: {$this->lon}",
            ],
            '_meta' => ['latitude' => $this->lat, 'longitude' => $this->lon],
        ], $this->additionalParams);
    }
}
