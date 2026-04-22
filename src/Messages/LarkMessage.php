<?php

namespace NotificationChannels\Lark\Messages;

class LarkMessage
{
    /** The recipient's open_id / user_id / chat_id / email / union_id */
    protected ?string $chatId = null;

    protected string $chatIdType = 'open_id';

    protected array $lines = [];

    protected bool $disableNotification = false;

    protected array $buttons = [];

    protected array $additionalParams = [];

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Create a new message instance.
     */
    public static function create(string $content = ''): static
    {
        return (new static())->content($content);
    }

    // ── Routing ───────────────────────────────────────────────────────────────

    /**
     * Set the recipient's ID.
     *
     * @param  string  $chatId  open_id / user_id / chat_id / email / union_id
     * @param  string  $type  open_id (default) | user_id | chat_id | email | union_id
     */
    public function to(string $chatId, string $type = 'open_id'): static
    {
        $this->chatId = $chatId;
        $this->chatIdType = $type;

        return $this;
    }

    // ── Content ───────────────────────────────────────────────────────────────

    /**
     * Set the message body text.
     * Mirrors: ->content("Hello there!")
     */
    public function content(string $text): static
    {
        if ($text !== '') {
            $this->lines[] = $text;
        }

        return $this;
    }

    /**
     * Append a new line of text.
     * Mirrors: ->line("Your invoice has been *PAID*")
     */
    public function line(string $text): static
    {
        $this->lines[] = $text;

        return $this;
    }

    /**
     * Conditionally append a line.
     * Mirrors: ->lineIf($notifiable->amount > 0, "Amount paid: {$amount}")
     */
    public function lineIf(bool $condition, string $text): static
    {
        if ($condition) {
            $this->lines[] = $text;
        }

        return $this;
    }

    /**
     * Disable push notification sound.
     * Mirrors: ->disableNotification()
     */
    public function disableNotification(bool $value = true): static
    {
        $this->disableNotification = $value;

        return $this;
    }

    // ── Buttons ───────────────────────────────────────────────────────────────

    /**
     * Add an inline URL button.
     * Mirrors: ->button('View Invoice', $url)
     */
    public function button(string $text, string $url, string $type = 'default'): static
    {
        $this->buttons[] = [
            'tag' => 'button',
            'text' => ['tag' => 'lark_md', 'content' => $text],
            'type' => $type,
            'url' => $url,
        ];

        return $this;
    }

    /**
     * Add a callback button (for bot interactions).
     * Mirrors: ->buttonWithCallback('Confirm', 'confirm_invoice 42')
     */
    public function buttonWithCallback(string $text, array $value): static
    {
        $this->buttons[] = [
            'tag' => 'button',
            'text' => ['tag' => 'plain_text', 'content' => $text],
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a multi-platform URL button.
     */
    public function buttonWithMultiUrl(string $text, array $urls): static
    {
        $this->buttons[] = [
            'tag' => 'button',
            'text' => ['tag' => 'plain_text', 'content' => $text],
            'multi_url' => [
                'url' => $urls['url'],
                'android_url' => $urls['android'] ?? null,
                'ios_url' => $urls['ios'] ?? null,
                'pc_url' => $urls['pc'] ?? null,
            ],
        ];

        return $this;
    }

    /**
     * Merge additional raw API parameters.
     * Mirrors: ->options(['disable_web_page_preview' => true])
     */
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

    public function getContent(): string
    {
        return implode("\n", $this->lines);
    }

    // ── Serialise to Lark API payload ─────────────────────────────────────────

    /**
     * Produce the final Lark API payload array.
     * Auto-upgrades to interactive card when buttons are present.
     */
    public function toArray(): array
    {
        $body = implode("\n", $this->lines);

        if (! empty($this->buttons)) {
            $elements = [
                ['tag' => 'div', 'text' => ['tag' => 'lark_md', 'content' => $body]],
                ['tag' => 'action', 'actions' => $this->buttons],
            ];

            return array_merge([
                'msg_type' => 'interactive',
                'content' => [
                    'config' => ['wide_screen_mode' => true],
                    'elements' => $elements,
                ],
            ], $this->additionalParams);
        }

        return array_merge([
            'msg_type' => 'text',
            'content' => ['text' => $body],
        ], $this->additionalParams);
    }
}
