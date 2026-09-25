<?php

namespace Restruct\BunnyStream\Tests;

use Restruct\BunnyStream\Admin\VideoAdmin;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Model\BunnyVideo;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * VideoAdmin: its configuration, and that the list and the edit form render in a real CMS
 * request (summary fields, getCMSFields, the session lookup) on each Silverstripe major.
 */
class VideoAdminTest extends FunctionalTest
{
    # Needed even for the config test: FunctionalTest::setUp() logs out, which queries
    # session-manager's LoginSession table when that module is installed (recipe-cms has it).
    protected $usesDatabase = true;

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

    protected function modelSegment(): string
    {
        return str_replace('\\', '-', BunnyVideo::class);
    }

    public function testConfiguration()
    {
        $config = VideoAdmin::config();
        $this->assertSame('videos', $config->get('url_segment'));
        $this->assertSame([BunnyVideo::class], $config->get('managed_models'));
        $this->assertSame('font-icon-block-media', $config->get('menu_icon_class'));
        $this->assertContains('CMS_ACCESS_LeftAndMain', (array) $config->get('required_permission_codes'));
    }

    public function testListRenders()
    {
        BunnyVideo::create(['VideoGuid' => 'g-1', 'Title' => 'Listed clip', 'Status' => BunnyStreamClient::STATUS_FINISHED, 'Duration' => 90])->write();
        $this->logInWithPermission('ADMIN');

        $response = $this->get('admin/videos/' . $this->modelSegment());

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Listed clip', $body);
        $this->assertStringContainsString('1:30', $body);
        $this->assertStringContainsString('/g-1/thumbnail.jpg', $body);
    }

    public function testEditFormRenders()
    {
        $video = BunnyVideo::create(['VideoGuid' => 'g-1', 'Title' => 'Edit me', 'Status' => BunnyStreamClient::STATUS_FINISHED]);
        $video->write();
        $this->logInWithPermission('ADMIN');

        $segment = $this->modelSegment();
        $response = $this->get("admin/videos/$segment/EditForm/field/$segment/item/{$video->ID}/edit");

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Afspeelopties', $body);
        $this->assertStringContainsString('name="EnforceFullWatch"', $body);
        $this->assertStringContainsString('iframe.mediadelivery.net/embed/', $body);
        $this->assertCount(0, MockBunnyClient::$history, 'a finished video is not re-synced');
    }

    public function testNonCmsUserIsRefused()
    {
        $this->logInWithPermission('SOME_OTHER_PERMISSION');
        # Look at the first response, not the login page it redirects to
        $this->autoFollowRedirection = false;
        $response = $this->get('admin/videos/' . $this->modelSegment());
        # LeftAndMain redirects users without access to the login form
        $this->assertNotSame(200, $response->getStatusCode());
    }
}
