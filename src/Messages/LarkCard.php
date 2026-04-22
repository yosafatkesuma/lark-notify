<?php

namespace NotificationChannels\Lark\Messages;

use NotificationChannels\Lark\Messages\Concerns\HasAttachments;

class LarkCard
{
    use HasAttachments;

    protected ?string $chatId = null;

    protected string $chatIdType = 'open_id';

    protected string $title = '';

    protected string $color = 'blue';

    protected array $lines = [];

    protected array $fields = [];

    protected array $actions = [];

    protected bool $wideScreen = true;

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Create a new card instance.
     */
    public static function create(string $title = ''): static
    {
        return (new static())->title($title);
    }

    // ── Routing ───────────────────────────────────────────────────────────────

    /** Mirrors: ->to($notifiable->lark_open_id) */
    public function to(string $chatId, string $type = 'open_id'): static
    {
        $this->chatId = $chatId;
        $this->chatIdType = $type;

        return $this;
    }

    // ── Header ────────────────────────────────────────────────────────────────

    /** Set the card header title */
    public function title(string $text): static
    {
        $this->title = $text;

        return $this;
    }

    /**
     * Set the header colour.
     * Options: blue | green | red | yellow | grey | purple
     */
    public function color(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    // ── Body ──────────────────────────────────────────────────────────────────

    /** Set the body text (Lark Markdown supported) */
    public function content(string $text): static
    {
        $this->lines[] = $text;

        return $this;
    }

    /** Mirrors: ->line("text") */
    public function line(string $text): static
    {
        $this->lines[] = $text;

        return $this;
    }

    /** Mirrors: ->lineIf($condition, "text") */
    public function lineIf(bool $condition, string $text): static
    {
        if ($condition) {
            $this->lines[] = $text;
        }

        return $this;
    }

    // ── Fields ────────────────────────────────────────────────────────────────

    /**
     * Add a two-column key/value field.
     *
     * @param  bool  $short  Render side-by-side (2 per row)
     */
    public function field(string $label, string $value, bool $short = true): static
    {
        $this->fields[] = compact('label', 'value', 'short');

        return $this;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Add a URL button.
     * Mirrors: ->button('View Invoice', $url)
     */
    public function button(string $text, string $url, string $type = 'default'): static
    {
        $this->actions[] = ['text' => $text, 'url' => $url, 'type' => $type];

        return $this;
    }

    /**
     * Add a callback button.
     * Mirrors: ->buttonWithCallback('Confirm', ['action' => 'confirm'])
     */
    public function buttonWithCallback(string $text, array $value): static
    {
        $this->actions[] = ['text' => $text, 'value' => $value, 'type' => 'default'];

        return $this;
    }

    /** Toggle wide-screen card layout */
    public function wideScreenMode(bool $value): static
    {
        $this->wideScreen = $value;

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
        $elements = [];

        if (! empty($this->lines)) {
            $elements[] = [
                'tag' => 'div',
                'text' => ['tag' => 'lark_md', 'content' => implode("\n", $this->lines)],
            ];
        }

        if (! empty($this->fields)) {
            $elements[] = [
                'tag' => 'div',
                'fields' => array_map(fn ($f) => [
                    'is_short' => $f['short'],
                    'text' => ['tag' => 'lark_md', 'content' => "**{$f['label']}**\n{$f['value']}"],
                ], $this->fields),
            ];
        }

        foreach ($this->buildAttachmentElements() as $el) {
            $elements[] = $el;
        }

        if (! empty($this->actions)) {
            $elements[] = [
                'tag' => 'action',
                'actions' => array_map(function ($a) {
                    $btn = [
                        'tag' => 'button',
                        'text' => ['tag' => 'plain_text', 'content' => $a['text']],
                        'type' => $a['type'] ?? 'default',
                    ];
                    if (isset($a['url'])) {
                        $btn['url'] = $a['url'];
                    }
                    if (isset($a['value'])) {
                        $btn['value'] = $a['value'];
                    }

                    return $btn;
                }, $this->actions),
            ];
        }

        return [
            'msg_type' => 'interactive',
            'content' => [
                'config' => ['wide_screen_mode' => $this->wideScreen],
                'header' => [
                    'template' => $this->color,
                    'title' => ['tag' => 'plain_text', 'content' => $this->title],
                ],
                'elements' => $elements,
            ],
        ];
    }
}
