<?php

namespace NotificationChannels\Lark\Messages;

use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

class LarkFile
{
    protected ?string $chatId = null;

    protected string $chatIdType = 'open_id';

    protected string $fileType = 'file';

    protected ?string $fileKey = null;

    /** Local path pending upload */
    protected ?string $localPath = null;

    /** UploadedFile instance pending upload */
    protected ?object $uploadedFile = null;

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
     * Set a local file path to be uploaded automatically when the
     * notification is sent. The channel calls uploader() internally.
     *
     * @example
     *   LarkFile::create()->uploadPath('/storage/reports/monthly.pdf')->to($id)
     *   LarkFile::create()->uploadPath(storage_path('app/photo.jpg'))->to($id)
     */
    public function uploadPath(string $path, ?string $forcedType = null): static
    {
        $this->localPath = $path;

        // Pre-detect the type so toArray() knows what msg_type to set
        if ($forcedType) {
            $this->fileType = $forcedType;
        } else {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $this->fileType = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','tiff'], true)
                ? 'image' : 'file';
        }

        return $this;
    }

    /**
     * Set a Laravel UploadedFile to be uploaded automatically.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     *
     * @example
     *   LarkFile::create()->uploadFromRequest($request->file('photo'))->to($id)
     */
    public function uploadFromRequest(object $file): static
    {
        $this->uploadedFile = $file;

        $mime = method_exists($file, 'getMimeType') ? ($file->getMimeType() ?? '') : '';
        if (str_starts_with($mime, 'image/'))      $this->fileType = 'image';
        elseif (str_starts_with($mime, 'video/'))  $this->fileType = 'media';
        elseif (str_starts_with($mime, 'audio/'))  $this->fileType = 'audio';
        else                                        $this->fileType = 'file';

        return $this;
    }

    /**
     * Check whether this message needs an upload before it can be sent.
     * Called by LarkChannel before dispatching.
     */
    public function needsUpload(): bool
    {
        return $this->localPath !== null || $this->uploadedFile !== null;
    }

    /**
     * Perform the upload using the given uploader and store the resulting key.
     * Called automatically by LarkChannel — you don't need to call this yourself.
     */
    public function performUpload(\NotificationChannels\Lark\LarkUploader $uploader): static
    {
        if ($this->uploadedFile !== null) {
            $this->fileKey = $uploader->uploadFromRequest($this->uploadedFile);
        } elseif ($this->localPath !== null) {
            $this->fileKey = ($this->fileType === 'image')
                ? $uploader->uploadImage($this->localPath)
                : $uploader->uploadFile($this->localPath);
        }

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
                'LarkFile requires a file key. Upload the file first via the Lark media API.'
            );
        }

        $contentKey = ($this->fileType === 'image') ? 'image_key' : 'file_key';

        $content = [$contentKey => $this->fileKey];

        if ($this->caption !== null) {
            $content['text'] = $this->caption;
        }

        return array_merge([
            'msg_type' => $this->fileType,
            'content'  => $content,
        ], $this->additionalParams);
    }
}
