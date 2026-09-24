<?php

namespace Restruct\BunnyStream\Tests;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The API client: URL building and signing (pure), and the HTTP calls (through a mock transport,
 * so nothing reaches Bunny).
 */
class BunnyStreamClientTest extends SapphireTest
{
    protected $usesDatabase = false;

    /** @var array Environment variables before the test, restored afterwards */
    protected array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = Environment::getVariables();
        MockBunnyClient::reset();
    }

    protected function tearDown(): void
    {
        Environment::setVariables($this->envBackup);
        MockBunnyClient::reset();
        parent::tearDown();
    }

    public function testCredentialsComeFromTheEnvironment()
    {
        Environment::setEnv('BUNNY_STREAM_API_KEY', 'env-key');
        Environment::setEnv('BUNNY_STREAM_LIBRARY_ID', '777');

        $client = BunnyStreamClient::create();
        $this->assertSame('env-key', $client->getApiKey());
        $this->assertSame(777, $client->getLibraryId());
    }

    public function testConstructorArgumentsOverrideTheEnvironment()
    {
        Environment::setEnv('BUNNY_STREAM_API_KEY', 'env-key');
        Environment::setEnv('BUNNY_STREAM_LIBRARY_ID', '777');

        $client = new BunnyStreamClient('arg-key', 55);
        $this->assertSame('arg-key', $client->getApiKey());
        $this->assertSame(55, $client->getLibraryId());
    }

    public function testCreateGoesThroughTheInjector()
    {
        # The seam the module relies on for BunnyVideo and BunnyUploadField
        Injector::inst()->load([BunnyStreamClient::class => ['class' => MockBunnyClient::class]]);
        $this->assertInstanceOf(MockBunnyClient::class, BunnyStreamClient::create());
    }

    public function testEmbedUrlIsUnsignedWithoutATokenKey()
    {
        $client = new BunnyStreamClient('k', 12, 'cdn.example.com', '');
        # Always iframe.mediadelivery.net, even with a CDN hostname configured
        $this->assertSame('https://iframe.mediadelivery.net/embed/12/abc-123', $client->getEmbedUrl('abc-123'));
    }

    public function testEmbedUrlIsSignedWithATokenKey()
    {
        $client = new BunnyStreamClient('k', 12, null, 'secret');
        $before = time();
        $url = $client->getEmbedUrl('abc-123', 600);
        $after = time();

        $this->assertMatchesRegularExpression(
            '#^https://iframe\.mediadelivery\.net/embed/12/abc-123\?token=([0-9a-f]{64})&expires=(\d+)$#',
            $url
        );
        preg_match('#token=([0-9a-f]{64})&expires=(\d+)#', $url, $m);
        $expires = (int) $m[2];
        $this->assertGreaterThanOrEqual($before + 600, $expires);
        $this->assertLessThanOrEqual($after + 600, $expires);
        # SHA256(token_auth_key + video_id + expires), per Bunny's embed token authentication
        $this->assertSame(hash('sha256', 'secret' . 'abc-123' . $expires), $m[1]);
    }

    public function testThumbnailUrlUsesTheLibraryPullZoneByDefault()
    {
        $client = new BunnyStreamClient('k', 12, null);
        $this->assertSame('https://vz-12.b-cdn.net/abc/thumbnail.jpg', $client->getThumbnailUrl('abc'));
    }

    public function testThumbnailUrlUsesTheConfiguredCdnHostname()
    {
        $client = new BunnyStreamClient('k', 12, 'video.example.com');
        $this->assertSame('https://video.example.com/abc/thumbnail.jpg', $client->getThumbnailUrl('abc'));
    }

    public function testTusUploadCredentials()
    {
        $client = new BunnyStreamClient('the-key', 12);
        $before = time();
        $creds = $client->getTusUploadCredentials('vid-1', 120);

        $this->assertSame(BunnyStreamClient::TUS_ENDPOINT, $creds['endpoint']);
        $headers = $creds['headers'];
        $this->assertSame(12, $headers['LibraryId']);
        $this->assertSame('vid-1', $headers['VideoId']);
        $this->assertGreaterThanOrEqual($before + 120, $headers['AuthorizationExpire']);
        # SHA256(library_id + api_key + expiration_time + video_id)
        $this->assertSame(
            hash('sha256', '12' . 'the-key' . $headers['AuthorizationExpire'] . 'vid-1'),
            $headers['AuthorizationSignature']
        );
    }

    public function testCreateVideoPostsTitleAndCollection()
    {
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'g-1', 'title' => 'Clip'])));
        $client = new MockBunnyClient();

        $video = $client->createVideo('Clip', 'coll-9');

        $this->assertSame('g-1', $video->guid);
        $request = MockBunnyClient::$history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/library/' . MockBunnyClient::LIBRARY_ID . '/videos', $request->getUri()->getPath());
        $this->assertSame(MockBunnyClient::API_KEY, $request->getHeaderLine('AccessKey'));
        $this->assertSame(['title' => 'Clip', 'collectionId' => 'coll-9'], json_decode((string) $request->getBody(), true));
    }

    public function testListVideosSendsPagingAndSearch()
    {
        MockBunnyClient::queue(new Response(200, [], json_encode(['items' => [], 'totalItems' => 0])));
        $client = new MockBunnyClient();

        $result = $client->listVideos(2, 10, 'cats');

        $this->assertSame(0, $result->totalItems);
        parse_str(MockBunnyClient::$history[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(['page' => '2', 'itemsPerPage' => '10', 'search' => 'cats'], $query);
    }

    public function testDeleteVideoSendsDeleteAndAcceptsAnEmptyBody()
    {
        MockBunnyClient::queue(new Response(200, [], ''));
        $client = new MockBunnyClient();

        $client->deleteVideo('g-1');

        $request = MockBunnyClient::$history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/library/' . MockBunnyClient::LIBRARY_ID . '/videos/g-1', $request->getUri()->getPath());
    }

    public function testIsReadyOnlyForTheFinishedStatus()
    {
        MockBunnyClient::queue(
            new Response(200, [], json_encode(['status' => BunnyStreamClient::STATUS_FINISHED])),
            new Response(200, [], json_encode(['status' => BunnyStreamClient::STATUS_TRANSCODING]))
        );
        $client = new MockBunnyClient();

        $this->assertTrue($client->isReady('g-1'));
        $this->assertFalse($client->isReady('g-1'));
    }

    public function testHttpErrorsPropagate()
    {
        MockBunnyClient::queue(new Response(404, [], '{"message":"not found"}'));
        $client = new MockBunnyClient();

        $this->expectException(ClientException::class);
        $client->getVideo('missing');
    }
}
