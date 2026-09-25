<?php

namespace Restruct\BunnyStream\Tests\Stub;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use SilverStripe\Dev\TestOnly;

/**
 * BunnyStreamClient with a queued, in-memory HTTP transport: nothing reaches Bunny.
 *
 * Registered through the Injector in a test's setUp(), so every BunnyStreamClient::create()
 * in the module code gets one of these. The queue and the request history are STATIC because
 * the module creates a fresh client per call; reset() must run in setUp() and tearDown() so no
 * state survives the test that set it (SapphireTest restores Config, not plain statics).
 */
class MockBunnyClient extends BunnyStreamClient implements TestOnly
{
    public const API_KEY = 'test-api-key';
    public const LIBRARY_ID = 4242;

    public static ?MockHandler $handler = null;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    public static array $history = [];

    public function __construct(?string $apiKey = null, ?int $libraryId = null, ?string $cdnHostname = null, ?string $tokenAuthKey = null)
    {
        # Fixed credentials, so assertions do not depend on the host's environment
        parent::__construct($apiKey ?? self::API_KEY, $libraryId ?? self::LIBRARY_ID, $cdnHostname, $tokenAuthKey);

        if (!static::$handler) {
            static::$handler = new MockHandler();
        }
        $stack = HandlerStack::create(static::$handler);
        $stack->push(Middleware::history(static::$history));
        $this->client = new Client(['handler' => $stack, 'timeout' => 1]);
    }

    public static function reset(): void
    {
        static::$handler = new MockHandler();
        static::$history = [];
    }

    /**
     * Queue responses (Response objects or Throwables) in the order they will be consumed.
     */
    public static function queue(...$responses): void
    {
        if (!static::$handler) {
            static::$handler = new MockHandler();
        }
        static::$handler->append(...$responses);
    }
}
