<?php

namespace Restruct\BunnyStream\Tests;

use GuzzleHttp\Psr7\Response;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Model\BunnyVideo;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use Restruct\BunnyStream\Tests\Stub\UploadTestController;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\SecurityToken;

/**
 * Issue #7 through real request routing: the HTTP status codes a browser gets back from
 * createUpload, and a request shaped the way the field's JS builds it (SecurityID query var).
 *
 * FunctionalTest disables SecurityToken in setUp(); these tests switch it back on, because the
 * token check is what is being tested (FunctionalTest::tearDown() enables it again anyway).
 */
class BunnyUploadFieldRoutingAccessTest extends FunctionalTest
{
    # FunctionalTest::setUp() logs out, which queries session-manager's LoginSession table
    protected $usesDatabase = true;

    # SapphireTest adds a route for each of these AHEAD of the CMS catch-all page route
    protected static $extra_controllers = [
        UploadTestController::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SecurityToken::enable();
        MockBunnyClient::reset();
        Injector::inst()->load([BunnyStreamClient::class => ['class' => MockBunnyClient::class]]);
    }

    protected function tearDown(): void
    {
        MockBunnyClient::reset();
        parent::tearDown();
    }

    public function testAnonymousGets403()
    {
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'should-not-exist'])));

        $response = $this->get('bunnytest/Form/field/BunnyVideoID/createUpload?title=x.mp4');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, BunnyVideo::get()->count());
    }

    public function testCmsUserWithoutTokenGets400()
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'should-not-exist'])));

        $response = $this->get('bunnytest/Form/field/BunnyVideoID/createUpload?title=x.mp4');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, BunnyVideo::get()->count());
    }

    public function testCmsUserWithTokenInTheQueryStringIsAllowed()
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'routed-token-guid'])));
        # The token the field would have rendered, stored where SecurityToken looks for it
        $token = 'routing-test-token';
        $this->session()->set(SecurityToken::inst()->getName(), $token);

        $response = $this->get(
            'bunnytest/Form/field/BunnyVideoID/createUpload?title=Routed.mp4&SecurityID=' . urlencode($token)
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('routed-token-guid', $data['videoGuid']);
    }
}
