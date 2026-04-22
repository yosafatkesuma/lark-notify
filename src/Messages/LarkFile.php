<?php

namespace NotificationChannels\Lark\Messages;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

class LarkFile
{
    protected ?string $chatId = null;

    protected string $chatIdType = 'open_id';

    protected string $fileType = 'file';

    protected ?string $fileKey = null;

    protected ?string $caption = null;

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

    // ── File types ────────────────────────────────────────────────────────────

    /**
     * Attach an image by image_key (upload first via Lark media API).
     * Mirrors: ->photo($fileId)
     */
    public function image(string $imageKey): static
    {
        $this->fileType = 'image';
        $this->fileKey = $imageKey;

        return $this;
    }

    /**
     * Attach a document by file_key.
     * Mirrors: ->document($fileId)
     */
    public function file(string $fileKey): static
    {
        $this->fileType = 'file';
        $this->fileKey = $fileKey;

        return $this;
    }

    /**
     * Attach audio by file_key.
     * Mirrors: ->audio($fileId)
     */
    public function audio(string $fileKey): static
    {
        $this->fileType = 'audio';
        $this->fileKey = $fileKey;

        return $this;
    }

    /**
     * Attach a video by file_key.
     * Mirrors: ->video($fileId)
     */
    public function video(string $fileKey): static
    {
        $this->fileType = 'media';
        $this->fileKey = $fileKey;

        return $this;
    }

    /**
     * Optional caption accompanying the file.
     * Mirrors: ->content("Here is your report")
     */
    public function content(string $text): static
    {
        $this->caption = $text;

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
        if (! $this->fileKey) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                'LarkFile requires a file key. Upload the file first via the Lark media API.',
            );
        }

        $contentKey = ($this->fileType === 'image') ? 'image_key' : 'file_key';

        $content = [$contentKey => $this->fileKey];

        if ($this->caption !== null) {
            $content['text'] = $this->caption;
        }

        return array_merge([
            'msg_type' => $this->fileType,
            'content' => $content,
        ], $this->additionalParams);
    }
}
