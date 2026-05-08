<?php

namespace Restruct\BunnyStream\Api;

use GuzzleHttp\Client;
use SilverStripe\Core\Environment;

/**
 * Bunny Stream API client.
 *
 * @link https://docs.bunny.net/reference/api-overview
 */
class BunnyStreamClient
{
    const API_BASE = 'https://video.bunnycdn.com';
    const TUS_ENDPOINT = 'https://video.bunnycdn.com/tusupload';

    const STATUS_CREATED = 0;
    const STATUS_UPLOADED = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_TRANSCODING = 3;
    const STATUS_FINISHED = 4;
    const STATUS_ERROR = 5;
    const STATUS_UPLOAD_FAILED = 6;

    protected string $apiKey;
    protected int $libraryId;
    protected string $cdnHostname;
    protected Client $client;

    public function __construct(?string $apiKey = null, ?int $libraryId = null, ?string $cdnHostname = null)
    {
        $this->apiKey = $apiKey ?: Environment::getEnv('BUNNY_STREAM_API_KEY') ?: '';
        $this->libraryId = $libraryId ?: (int) (Environment::getEnv('BUNNY_STREAM_LIBRARY_ID') ?: 0);
        $this->cdnHostname = $cdnHostname ?: Environment::getEnv('BUNNY_STREAM_CDN_HOSTNAME') ?: '';
        $this->client = new Client(['timeout' => 30]);
    }

    public function getLibraryId(): int
    {
        return $this->libraryId;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    // -------------------------------------------------------------------------
    // Video CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a video object (must be created before TUS upload).
     *
     * @return object{guid: string, videoLibraryId: int, title: string, status: int}
     */
    public function createVideo(string $title, ?string $collectionId = null): object
    {
        $body = ['title' => $title];
        if ($collectionId) {
            $body['collectionId'] = $collectionId;
        }

        return $this->request('POST', "/library/{$this->libraryId}/videos", $body);
    }

    /**
     * Get video details.
     *
     * @return object{guid: string, title: string, status: int, length: int, width: int, height: int, encodeProgress: int, ...}
     */
    public function getVideo(string $videoId): object
    {
        return $this->request('GET', "/library/{$this->libraryId}/videos/{$videoId}");
    }

    /**
     * List videos in the library.
     *
     * @return object{items: array, totalItems: int, currentPage: int}
     */
    public function listVideos(int $page = 1, int $perPage = 100, ?string $search = null): object
    {
        $params = ['page' => $page, 'itemsPerPage' => $perPage];
        if ($search) {
            $params['search'] = $search;
        }
        return $this->request('GET', "/library/{$this->libraryId}/videos", null, $params);
    }

    /**
     * Delete a video.
     */
    public function deleteVideo(string $videoId): void
    {
        $this->request('DELETE', "/library/{$this->libraryId}/videos/{$videoId}");
    }

    // -------------------------------------------------------------------------
    // TUS upload helpers
    // -------------------------------------------------------------------------

    /**
     * Generate TUS upload credentials for direct browser-to-Bunny upload.
     *
     * Returns the data needed by the TUS JS client:
     * - endpoint: TUS upload URL
     * - headers: auth headers (signature, expiry, libraryId, videoId)
     *
     * @param string $videoId The GUID from createVideo()
     * @param int $expiresInSeconds How long the upload URL is valid (default: 1 hour)
     * @return array{endpoint: string, headers: array{AuthorizationSignature: string, AuthorizationExpire: int, LibraryId: int, VideoId: string}}
     */
    public function getTusUploadCredentials(string $videoId, int $expiresInSeconds = 3600): array
    {
        $expireTimestamp = time() + $expiresInSeconds;

        # Signature = SHA256(library_id + api_key + expiration_time + video_id)
        $signature = hash('sha256', $this->libraryId . $this->apiKey . $expireTimestamp . $videoId);

        return [
            'endpoint' => self::TUS_ENDPOINT,
            'headers' => [
                'AuthorizationSignature' => $signature,
                'AuthorizationExpire' => $expireTimestamp,
                'LibraryId' => $this->libraryId,
                'VideoId' => $videoId,
            ],
        ];
    }

    /**
     * Get the embed/player URL for a video.
     */
    public function getEmbedUrl(string $videoId): string
    {
        $host = $this->cdnHostname ?: "iframe.mediadelivery.net";
        return "https://{$host}/embed/{$this->libraryId}/{$videoId}";
    }

    /**
     * Get the thumbnail URL for a video.
     */
    public function getThumbnailUrl(string $videoId): string
    {
        $host = $this->cdnHostname ?: "vz-{$this->libraryId}.b-cdn.net";
        return "https://{$host}/{$videoId}/thumbnail.jpg";
    }

    /**
     * Check if a video is ready for playback.
     */
    public function isReady(string $videoId): bool
    {
        $video = $this->getVideo($videoId);
        return ($video->status ?? -1) === self::STATUS_FINISHED;
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    protected function request(string $method, string $path, ?array $body = null, ?array $query = null): object
    {
        $options = [
            'headers' => [
                'AccessKey' => $this->apiKey,
                'Accept' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        if ($query) {
            $options['query'] = $query;
        }

        $response = $this->client->request($method, self::API_BASE . $path, $options);
        $content = $response->getBody()->getContents();

        if (empty($content)) {
            return (object) [];
        }

        return json_decode($content);
    }
}
