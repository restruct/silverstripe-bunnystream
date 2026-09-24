<?php

namespace Restruct\BunnyStream\Tests;

use GuzzleHttp\Psr7\Response;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Model\BunnyVideo;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use Restruct\BunnyStream\Tests\Stub\UploadTestController;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * The createUpload endpoint reached through request routing (form -> field -> action), which
 * exercises the field's $allowed_actions / $url_handlers config on each Silverstripe major.
 */
class BunnyUploadFieldRoutingTest extends FunctionalTest
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
        MockBunnyClient::reset();
        Injector::inst()->load([BunnyStreamClient::class => ['class' => MockBunnyClient::class]]);
    }

    protected function tearDown(): void
    {
        MockBunnyClient::reset();
        parent::tearDown();
    }

    public function testCreateUploadIsRoutedToTheField()
    {
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'routed-guid'])));

        $response = $this->get('bunnytest/Form/field/BunnyVideoID/createUpload?title=Routed.mp4');

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('routed-guid', $data['videoGuid']);
        $this->assertSame('Routed.mp4', BunnyVideo::get()->byID($data['bunnyVideoId'])->Title);
    }

    public function testOtherFieldActionsAreNotAllowed()
    {
        # Field() is a public method but not an allowed action: RequestHandler::hasAction() says
        # no, so the framework answers 404 ("isn't available") before its 403 check is reached.
        $response = $this->get('bunnytest/Form/field/BunnyVideoID/Field');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('bunny-upload-field', $response->getBody());
    }
}
