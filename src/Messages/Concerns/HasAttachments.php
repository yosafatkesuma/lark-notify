<?php

namespace NotificationChannels\Lark\Messages\Concerns;

use NotificationChannels\Lark\LarkUploader;

/**
 * Shared attachment upload logic for LarkMessage and LarkCard.
 *
 * Each attachment can be sourced from one of four inputs:
 *
 *   1. Pre-uploaded key   image('img_key_abc') / file('file_key_xyz')
 *   2. Local file path    attachImage('/path/to/photo.jpg')
 *   3. Laravel UploadedFile  attachFromRequest($request->file('photo'))
 *   4. Raw binary content attachImageData($bytes, 'photo.jpg')
 *
 * Pending uploads (2, 3, 4) are resolved automatically by LarkChannel
 * before the message is sent.
 */
trait HasAttachments
{
    /**
     * Attachment entries.
     *
     * Shape of each entry:
     * [
     *   'kind'          => 'image'|'file'|'audio'|'video',
     *   'key'           => string|null,   // resolved image_key or file_key
     *   'localPath'     => string|null,   // local filesystem path
     *   'uploadedFile'  => object|null,   // Laravel UploadedFile instance
     *   'binaryContent' => string|null,   // raw binary bytes
     *   'filename'      => string|null,   // filename for binary uploads (e.g. 'photo.jpg')
     *   'caption'       => string|null,   // optional label shown above attachment
     * ]
     */
    protected array $attachments = [];

    // ── 1. Attach by pre-uploaded key ─────────────────────────────────────────

    /**
     * Attach an image using an already-uploaded image_key.
     *
     * @example
     *   ->image('img_key_abc123')
     *   ->image('img_key_abc123', 'Architecture diagram')
     */
    public function image(string $imageKey, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('image', key: $imageKey, caption: $caption);

        return $this;
    }

    /**
     * Attach a file using an already-uploaded file_key.
     *
     * @example
     *   ->file('file_key_xyz789')
     */
    public function file(string $fileKey, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('file', key: $fileKey, caption: $caption);

        return $this;
    }

    /**
     * Attach audio using an already-uploaded file_key.
     *
     * @example
     *   ->audio('file_key_abc')
     */
    public function audio(string $fileKey, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('audio', key: $fileKey, caption: $caption);

        return $this;
    }

    /**
     * Attach a video using an already-uploaded file_key.
     *
     * @example
     *   ->video('file_key_def')
     */
    public function video(string $fileKey, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('video', key: $fileKey, caption: $caption);

        return $this;
    }

    // ── 2. Attach by local file path ──────────────────────────────────────────

    /**
     * Attach a local image file — uploaded automatically before sending.
     *
     * @example
     *   ->attachImage(storage_path('app/photo.jpg'))
     *   ->attachImage('/tmp/banner.png', 'New banner')
     */
    public function attachImage(string $path, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('image', localPath: $path, caption: $caption);

        return $this;
    }

    /**
     * Attach a local file (PDF, DOCX, etc.) — uploaded automatically before sending.
     *
     * @example
     *   ->attachFile(storage_path('app/reports/monthly.pdf'), 'April report')
     */
    public function attachFile(string $path, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('file', localPath: $path, caption: $caption);

        return $this;
    }

    /**
     * Attach a local audio file — uploaded automatically before sending.
     *
     * @example
     *   ->attachAudio(storage_path('app/voice.mp3'))
     */
    public function attachAudio(string $path, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('audio', localPath: $path, caption: $caption);

        return $this;
    }

    /**
     * Attach a local video file — uploaded automatically before sending.
     *
     * @example
     *   ->attachVideo(storage_path('app/demo.mp4'))
     */
    public function attachVideo(string $path, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry('video', localPath: $path, caption: $caption);

        return $this;
    }

    // ── 3. Attach from Laravel UploadedFile ───────────────────────────────────

    /**
     * Attach a file directly from a Laravel HTTP request.
     * MIME type is used to auto-detect image / audio / video / file.
     *
     * @param \Illuminate\Http\UploadedFile $uploadedFile
     *
     * @example
     *   ->attachFromRequest($request->file('photo'))
     *   ->attachFromRequest($request->file('document'), 'Monthly report')
     */
    public function attachFromRequest(object $uploadedFile, ?string $caption = null): static
    {
        $mime = method_exists($uploadedFile, 'getMimeType')
            ? ($uploadedFile->getMimeType() ?? '')
            : '';

        $kind = $this->kindFromMime($mime);

        $this->attachments[] = $this->makeEntry($kind, uploadedFile: $uploadedFile, caption: $caption);

        return $this;
    }

    // ── 4. Attach from raw binary content ─────────────────────────────────────

    /**
     * Attach an image from raw binary content (bytes).
     *
     * Use this when you have image data in memory — from an API response,
     * S3/GCS download, GD/Imagick generation, base64 decode, etc.
     *
     * @param string $binaryContent Raw binary bytes (not base64)
     * @param string $filename Filename with extension, e.g. 'photo.jpg'
     *                            The extension determines the Lark image type.
     *
     * @example
     *   // From file_get_contents / HTTP response body
     *   ->attachImageData(file_get_contents('/tmp/photo.jpg'), 'photo.jpg')
     *
     *   // From S3 / Flysystem
     *   ->attachImageData(Storage::get('images/banner.png'), 'banner.png')
     *
     *   // From HTTP client (e.g. fetching a remote image)
     *   ->attachImageData($response->body(), 'avatar.jpg', 'Profile photo')
     *
     *   // From base64 string
     *   ->attachImageData(base64_decode($base64String), 'chart.png')
     *
     *   // From GD resource
     *   ob_start(); imagepng($gdImage); $bytes = ob_get_clean();
     *   ->attachImageData($bytes, 'generated.png', 'Generated chart')
     */
    public function attachImageData(string $imgKey, string $filename, ?string $caption = null, ?string $mode = 'fit_horizontal', ?bool $preview = true): static
    {
        $this->attachments[] = $this->makeEntry(
            'image',
            binaryContent: $imgKey,
            filename: $filename,
            caption: $caption,
        );

        return $this;
    }

    /**
     * Attach a file from raw binary content (bytes).
     *
     * @param string $binaryContent Raw binary bytes
     * @param string $filename Filename with extension, e.g. 'report.pdf'
     *
     * @example
     *   // PDF generated by a library (e.g. DomPDF, Snappy)
     *   ->attachFileData($pdf->output(), 'invoice.pdf', 'Invoice #42')
     *
     *   // Excel file from Maatwebsite/Excel
     *   ->attachFileData(Excel::raw(new ReportExport, \Maatwebsite\Excel\Excel::XLSX), 'report.xlsx')
     *
     *   // From Storage / S3
     *   ->attachFileData(Storage::get('reports/april.pdf'), 'april.pdf', 'April report')
     *
     *   // From HTTP response (downloading a file from another service)
     *   ->attachFileData(Http::get('https://api.example.com/export')->body(), 'export.csv')
     */
    public function attachFileData(string $binaryContent, string $filename, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry(
            'file',
            binaryContent: $binaryContent,
            filename: $filename,
            caption: $caption,
        );

        return $this;
    }

    /**
     * Attach audio from raw binary content (bytes).
     *
     * @param string $binaryContent Raw binary bytes
     * @param string $filename Filename with extension, e.g. 'voice.mp3' or 'message.opus'
     *
     * @example
     *   ->attachAudioData($response->body(), 'voice.opus')
     *   ->attachAudioData(Storage::get('audio/greeting.mp3'), 'greeting.mp3')
     */
    public function attachAudioData(string $binaryContent, string $filename, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry(
            'audio',
            binaryContent: $binaryContent,
            filename: $filename,
            caption: $caption,
        );

        return $this;
    }

    /**
     * Attach a video from raw binary content (bytes).
     *
     * @param string $binaryContent Raw binary bytes
     * @param string $filename Filename with extension, e.g. 'demo.mp4'
     *
     * @example
     *   ->attachVideoData(Storage::get('videos/demo.mp4'), 'demo.mp4', 'Product demo')
     *   ->attachVideoData(Http::get('https://cdn.example.com/clip.mp4')->body(), 'clip.mp4')
     */
    public function attachVideoData(string $binaryContent, string $filename, ?string $caption = null): static
    {
        $this->attachments[] = $this->makeEntry(
            'video',
            binaryContent: $binaryContent,
            filename: $filename,
            caption: $caption,
        );

        return $this;
    }

    // ── Upload resolution (called by LarkChannel) ─────────────────────────────

    /**
     * Returns true if any attachment still needs uploading.
     */
    public function needsUpload(): bool
    {
        foreach ($this->attachments as $a) {
            if ($a['key'] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upload all pending attachments and store their resolved keys.
     * Called automatically by LarkChannel before sending — do not call manually.
     */
    public function resolveAttachments(LarkUploader $uploader): static
    {
        foreach ($this->attachments as &$a) {
            if ($a['key'] !== null) {
                continue;
            } // already resolved

            // Source 3: Laravel UploadedFile
            if ($a['uploadedFile'] !== null) {
                $a['key'] = $uploader->uploadFromRequest($a['uploadedFile']);

                continue;
            }

            // Source 4: raw binary content
            if ($a['binaryContent'] !== null) {
                $a['key'] = match ($a['kind']) {
                    'image' => $uploader->uploadImageFromContent($a['binaryContent'], $a['filename']),
                    'audio' => $uploader->uploadFileFromContent($a['binaryContent'], $a['filename'], $this->detectAudioType($a['filename'])),
                    'video' => $uploader->uploadFileFromContent($a['binaryContent'], $a['filename'], 'mp4'),
                    default => $uploader->uploadFileFromContent($a['binaryContent'], $a['filename']),
                };

                continue;
            }

            // Source 2: local file path
            if ($a['localPath'] !== null) {
                $a['key'] = match ($a['kind']) {
                    'image' => $uploader->uploadImage($a['localPath']),
                    'audio' => $uploader->uploadAudio($a['localPath']),
                    'video' => $uploader->uploadVideo($a['localPath']),
                    default => $uploader->uploadFile($a['localPath']),
                };
            }
        }
        unset($a);

        return $this;
    }

    /**
     * Build Lark card element blocks for all resolved attachments.
     * Called by toArray() in LarkMessage and LarkCard.
     */
    protected function buildAttachmentElements(): array
    {
        $elements = [];

        foreach ($this->attachments as $a) {
            $elements[] = match ($a['kind']) {
                'image' => [
                    'tag' => 'img',
                    'img_key' => $a['binaryContent'],
                    'alt' => ['tag' => 'plain_text', 'content' => ''],
                    'mode' => $a['imgMode'],
                    'preview' => $a['imgPreview']
                ],
            };
        }

        return $elements;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function makeEntry(
        string  $kind,
        ?string $key = null,
        ?string $localPath = null,
        ?object $uploadedFile = null,
        ?string $binaryContent = null,
        ?string $filename = null,
        ?string $caption = null,
        ?string $imgMode = null,
        ?bool   $imgPreview = null,
    ): array
    {
        return compact(
            'kind',
            'key',
            'localPath',
            'uploadedFile',
            'binaryContent',
            'filename',
            'caption',
            'imgMode',
            'imgPreview');
    }

    private function kindFromMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function detectAudioType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'opus' => 'opus',
            default => 'mp3',
        };
    }
}
