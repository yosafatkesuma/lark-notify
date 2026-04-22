<?php

namespace NotificationChannels\Lark;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use NotificationChannels\Lark\Exceptions\CouldNotSendNotification;

/**
 * Handles uploading files and images to the Lark media API.
 *
 * Usage:
 *   $uploader = new LarkUploader($client);
 *
 *   $imageKey = $uploader->uploadImage('/path/to/photo.jpg');
 *   $fileKey  = $uploader->uploadFile('/path/to/report.pdf');
 *   $videoKey = $uploader->uploadVideo('/path/to/clip.mp4');
 */
class LarkUploader
{
    /**
     * Lark-supported image extensions.
     * Used by: uploadImage(), assertExtension()
     */
    const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff'];

    /**
     * Lark-supported file extensions mapped to their Lark file_type value.
     *
     * Key   = file extension (lowercase)
     * Value = the file_type string Lark's API expects in the multipart body
     *
     * Used by:
     *   - uploadFile()     -> assertExtension() validates the key exists here
     *   - uploadAudio()    -> assertExtension() validates opus / mp3 subset
     *   - uploadVideo()    -> assertExtension() validates mp4 subset
     *   - detectFileType() -> looks up the value to pass to the Lark API
     */
    const FILE_TYPES = [
        // Documents
        'pdf' => 'pdf',
        'doc' => 'doc',
        'xls' => 'xls',
        'ppt' => 'ppt',
        // Audio
        'opus' => 'opus',
        // Video
        'mp4' => 'mp4',
        // Generic binary fallback
        'stream' => 'stream',
    ];

    /** Subset of FILE_TYPES keys considered audio — validated in uploadAudio() */
    const AUDIO_EXTENSIONS = ['opus'];

    /** Subset of FILE_TYPES keys considered video — validated in uploadVideo() */
    const VIDEO_EXTENSIONS = ['mp4'];

    public function __construct(
        protected LarkClient $larkClient,
        protected HttpClient $http,
        protected string $baseUri = 'https://open.larksuite.com/open-apis',
        protected int $timeout = 30,
    ) {}

    // ── Image upload ──────────────────────────────────────────────────────────

    /**
     * Upload an image from a local file path and return its image_key.
     *
     * Validates extension against IMAGE_TYPES before uploading.
     *
     * @param  string  $path  Absolute path to the image file
     * @param  string  $imageType  'message' (default) | 'avatar'
     * @return string image_key   Use this in LarkFile::create()->image($key)
     *
     * @throws CouldNotSendNotification if file missing or extension unsupported
     */
    public function uploadImage(string $path, string $imageType = 'message'): string
    {
        $this->assertFileExists($path);
        $this->assertExtension($path, self::IMAGE_TYPES, 'image');

        return $this->doUploadImage($path, $imageType);
    }

    /**
     * Upload an image from a remote URL (downloads to a temp file first).
     */
    public function uploadImageFromUrl(string $url, string $imageType = 'message'): string
    {
        $tempPath = $this->downloadToTemp($url);

        try {
            return $this->doUploadImage($tempPath, $imageType);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Upload an image from raw binary content.
     *
     * @param  string  $content  Raw binary bytes
     * @param  string  $filename  e.g. 'photo.jpg' — sets the filename in the request
     */
    public function uploadImageFromContent(
        string $content,
        string $filename,
        string $imageType = 'message',
    ): string {
        $tempPath = $this->contentToTemp($content, $filename);

        try {
            return $this->doUploadImage($tempPath, $imageType);
        } finally {
            @unlink($tempPath);
        }
    }

    // ── File upload ───────────────────────────────────────────────────────────

    /**
     * Upload a file (document, PDF, archive, etc.) and return its file_key.
     *
     * Validates extension against FILE_TYPES keys.
     * Uses FILE_TYPES to look up the correct Lark file_type value for the API call.
     *
     * @param  string  $path  Absolute path to the file
     * @param  string|null  $fileType  Override the detected Lark file_type.
     *                                 If null, auto-detected from FILE_TYPES.
     * @return string file_key  Use this in LarkFile::create()->file($key)
     *
     * @throws CouldNotSendNotification if file missing or extension not in FILE_TYPES
     */
    public function uploadFile(string $path, ?string $fileType = null): string
    {
        $this->assertFileExists($path);

        if ($fileType === null) {
            $this->assertExtension($path, array_keys(self::FILE_TYPES), 'file');
        }

        $type = $fileType ?? $this->detectFileType($path);

        return $this->doUploadFile($path, $type);
    }

    /**
     * Upload a file from raw binary content.
     */
    public function uploadFileFromContent(
        string $content,
        string $filename,
        ?string $fileType = null,
    ): string {
        $tempPath = $this->contentToTemp($content, $filename);

        if ($fileType === null) {
            $this->assertExtension($tempPath, array_keys(self::FILE_TYPES), 'file');
        }

        $type = $fileType ?? $this->detectFileType($tempPath);

        try {
            return $this->doUploadFile($tempPath, $type);
        } finally {
            @unlink($tempPath);
        }
    }

    // ── Audio upload ──────────────────────────────────────────────────────────

    /**
     * Upload an audio file and return its file_key.
     *
     * Validates the extension against AUDIO_EXTENSIONS (subset of FILE_TYPES).
     * Uses FILE_TYPES to resolve the Lark file_type value.
     *
     * @return string file_key  Use this in LarkFile::create()->audio($key)
     *
     * @throws CouldNotSendNotification if file missing or extension not in AUDIO_EXTENSIONS
     */
    public function uploadAudio(string $path, ?string $fileType = null): string
    {
        $this->assertFileExists($path);

        if ($fileType === null) {
            $this->assertExtension($path, self::AUDIO_EXTENSIONS, 'audio');
        }

        $type = $fileType ?? $this->detectFileType($path);

        return $this->doUploadFile($path, $type);
    }

    // ── Video upload ──────────────────────────────────────────────────────────

    /**
     * Upload a video file and return its file_key.
     *
     * Validates the extension against VIDEO_EXTENSIONS (subset of FILE_TYPES).
     * Uses FILE_TYPES to resolve the Lark file_type value ('mp4').
     *
     * @return string file_key  Use this in LarkFile::create()->video($key)
     *
     * @throws CouldNotSendNotification if file missing or extension not in VIDEO_EXTENSIONS
     */
    public function uploadVideo(string $path): string
    {
        $this->assertFileExists($path);
        $this->assertExtension($path, self::VIDEO_EXTENSIONS, 'video');

        $type = $this->detectFileType($path); // resolves to 'mp4' via FILE_TYPES

        return $this->doUploadFile($path, $type);
    }

    // ── Laravel UploadedFile support ──────────────────────────────────────────

    /**
     * Upload directly from a Laravel UploadedFile instance.
     * Routes to the correct upload method based on MIME type,
     * which in turn validates against IMAGE_TYPES / FILE_TYPES / etc.
     *
     * @param  \Illuminate\Http\UploadedFile  $uploadedFile
     * @return string image_key or file_key
     *
     * @example
     *   $key = $uploader->uploadFromRequest($request->file('attachment'));
     */
    public function uploadFromRequest(object $uploadedFile): string
    {
        $path = $uploadedFile->getRealPath();
        $mimeType = $uploadedFile->getMimeType() ?? '';

        if (str_starts_with($mimeType, 'image/')) {
            return $this->uploadImage($path);
        }

        if (str_starts_with($mimeType, 'video/')) {
            return $this->uploadVideo($path);
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return $this->uploadAudio($path);
        }

        return $this->uploadFile($path);
    }

    // ── Private: actual API calls ─────────────────────────────────────────────

    private function doUploadImage(string $path, string $imageType): string
    {
        $token = $this->larkClient->getTenantToken();

        try {
            $response = $this->http->post("{$this->baseUri}/im/v1/images", [
                'timeout' => $this->timeout,
                'headers' => ['Authorization' => "Bearer {$token}"],
                'multipart' => [
                    ['name' => 'image_type', 'contents' => $imageType],
                    ['name' => 'image', 'contents' => fopen($path, 'r'), 'filename' => basename($path)],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (($data['code'] ?? -1) !== 0) {
                throw CouldNotSendNotification::larkRespondedWithAnError(
                    (int) ($data['code'] ?? -1),
                    (string) ($data['msg'] ?? 'unknown'),
                );
            }

            if (empty($data['data']['image_key'])) {
                throw CouldNotSendNotification::couldNotCommunicateWithLark(
                    'Image upload succeeded but no image_key was returned.',
                );
            }

            return $data['data']['image_key'];

        } catch (GuzzleException $e) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                "Image upload failed: {$e->getMessage()}",
            );
        }
    }

    private function doUploadFile(string $path, string $fileType): string
    {
        $token = $this->larkClient->getTenantToken();
        $filename = basename($path);
        $size = filesize($path);

        try {
            $response = $this->http->post("{$this->baseUri}/im/v1/files", [
                'timeout' => $this->timeout,
                'headers' => ['Authorization' => "Bearer {$token}"],
                'multipart' => [
                    ['name' => 'file_type', 'contents' => $fileType],
                    ['name' => 'file_name', 'contents' => $filename],
                    ['name' => 'duration',  'contents' => '0'],
                    ['name' => 'file', 'contents' => fopen($path, 'r'), 'filename' => $filename,
                        'headers' => ['Content-Length' => (string) $size]],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (($data['code'] ?? -1) !== 0) {
                throw CouldNotSendNotification::larkRespondedWithAnError(
                    (int) ($data['code'] ?? -1),
                    (string) ($data['msg'] ?? 'unknown'),
                );
            }

            if (empty($data['data']['file_key'])) {
                throw CouldNotSendNotification::couldNotCommunicateWithLark(
                    'File upload succeeded but no file_key was returned.',
                );
            }

            return $data['data']['file_key'];

        } catch (GuzzleException $e) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                "File upload failed: {$e->getMessage()}",
            );
        }
    }

    // ── Private: helpers ─────────────────────────────────────────────────────

    private function assertFileExists(string $path): void
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                "File not found or not readable: {$path}",
            );
        }
    }

    /**
     * Assert the file extension is in the $allowed list.
     *
     * @param  array  $allowed  e.g. IMAGE_TYPES, FILE_TYPES keys, AUDIO_EXTENSIONS
     */
    private function assertExtension(string $path, array $allowed, string $type): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($ext, $allowed, true)) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                "Unsupported {$type} extension '.{$ext}'. Allowed: " . implode(', ', $allowed),
            );
        }
    }

    /**
     * Look up the Lark file_type string for a given path using FILE_TYPES.
     * This is the single source of truth — FILE_TYPES drives the mapping.
     *
     * Falls back to 'stream' for unknown extensions.
     */
    private function detectFileType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::FILE_TYPES[$ext] ?? 'stream';
    }

    private function downloadToTemp(string $url): string
    {
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'tmp';
        $tempPath = sys_get_temp_dir() . '/lark_upload_' . uniqid() . '.' . $ext;

        try {
            $this->http->get($url, ['sink' => $tempPath, 'timeout' => $this->timeout]);
        } catch (GuzzleException $e) {
            throw CouldNotSendNotification::couldNotCommunicateWithLark(
                "Failed to download file from URL: {$e->getMessage()}",
            );
        }

        return $tempPath;
    }

    private function contentToTemp(string $content, string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION) ?: 'tmp';
        $tempPath = sys_get_temp_dir() . '/lark_upload_' . uniqid() . '.' . $ext;

        file_put_contents($tempPath, $content);

        return $tempPath;
    }
}
