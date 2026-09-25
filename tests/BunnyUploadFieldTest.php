<?php

namespace Restruct\BunnyStream\Tests;

use GuzzleHttp\Psr7\Response;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Forms\BunnyUploadField;
use Restruct\BunnyStream\Model\BunnyVideo;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use Restruct\BunnyStream\Tests\Stub\UploadTestController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\View\Requirements;

/**
 * BunnyUploadField: the rendered markup (empty and with a video attached) and the createUpload
 * endpoint that registers a video on Bunny and hands the browser its TUS credentials.
 */
class BunnyUploadFieldTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected ?UploadTestController $controller = null;

    protected function setUp(): void
    {
        parent::setUp();
        MockBunnyClient::reset();
        Injector::inst()->load([BunnyStreamClient::class => ['class' => MockBunnyClient::class]]);
        Requirements::clear();
    }

    protected function tearDown(): void
    {
        if ($this->controller) {
            $this->controller->popCurrent();
            $this->controller = null;
        }
        MockBunnyClient::reset();
        Requirements::clear();
        parent::tearDown();
    }

    /**
     * A field inside a form on a controller that is current, with the given GET vars.
     */
    protected function makeField($value = null, array $getVars = []): BunnyUploadField
    {
        $request = new HTTPRequest('GET', 'bunnytest/Form/field/BunnyVideoID/createUpload', $getVars);
        $request->setSession(new Session([]));
        $this->controller = UploadTestController::create();
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();

        $field = BunnyUploadField::create('BunnyVideoID', 'Video');
        Form::create($this->controller, 'Form', FieldList::create($field), FieldList::create());
        if ($value !== null) {
            $field->setValue($value);
        }
        return $field;
    }

    public function testRendersAnEmptyUploadField()
    {
        $field = $this->makeField();
        $field->setDescription('Pick a video file');

        $html = (string) $field->Field();

        $this->assertStringContainsString('class="bunny-upload-field"', $html);
        $this->assertStringContainsString('<input type="hidden" name="BunnyVideoID"', $html);
        $this->assertMatchesRegularExpression('#data-create-url="[^"]*bunnytest/Form/field/BunnyVideoID/createUpload"#', $html);
        $this->assertStringContainsString('accept="video/*"', $html);
        # No video yet: the upload block is visible and there is no preview
        $this->assertMatchesRegularExpression('#_upload" style="display:block;"#', $html);
        $this->assertStringNotContainsString('_preview"', $html);
        # The description is moved into the upload block and blanked on the field itself
        $this->assertStringContainsString('Pick a video file', $html);
        $this->assertSame('', (string) $field->getDescription());

        $this->assertSame('bunny-upload', $field->Type());
    }

    public function testRegistersItsScripts()
    {
        $this->makeField()->Field();

        $scripts = implode(' ', array_keys(Requirements::backend()->getJavascript()));
        $this->assertStringContainsString('tus-js-client', $scripts);
        $this->assertStringContainsString('bunny-upload-field.js', $scripts);
    }

    public function testRendersTheAttachedVideo()
    {
        $video = BunnyVideo::create([
            'VideoGuid' => 'g-1',
            'Title' => 'Holiday <clip>',
            'Status' => BunnyStreamClient::STATUS_FINISHED,
            'Duration' => 65,
        ]);
        $video->write();

        $html = (string) $this->makeField($video->ID)->Field();

        $this->assertStringContainsString('value="' . $video->ID . '"', $html);
        $this->assertStringContainsString('_preview"', $html);
        $this->assertStringContainsString('Holiday &lt;clip&gt;', $html);
        $this->assertStringContainsString('Gereed', $html);
        $this->assertStringContainsString('1:05', $html);
        $this->assertStringContainsString('/g-1/thumbnail.jpg', $html);
        # A video is attached: the upload block starts hidden behind the preview
        $this->assertMatchesRegularExpression('#_upload" style="display:none;"#', $html);
    }

    /**
     * Hardening: the value is echoed into an HTML attribute, and after a failed submit it is
     * whatever the browser sent, so it has to be escaped.
     */
    public function testValueIsEscaped()
    {
        $html = (string) $this->makeField('1" onfocus="alert(1)')->Field();

        $this->assertStringNotContainsString('onfocus="alert(1)"', $html);
        $this->assertStringContainsString('value="1&quot; onfocus=&quot;alert(1)"', $html);
    }

    public function testCreateUploadRegistersTheVideoAndReturnsTusCredentials()
    {
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'new-guid'])));
        $field = $this->makeField(null, ['title' => 'My upload.mp4']);

        $response = $field->createUpload();

        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $data = json_decode($response->getBody(), true);
        $this->assertSame('new-guid', $data['videoGuid']);
        $this->assertSame(BunnyStreamClient::TUS_ENDPOINT, $data['tusEndpoint']);
        $this->assertSame('new-guid', $data['tusHeaders']['VideoId']);
        $this->assertSame(MockBunnyClient::LIBRARY_ID, $data['tusHeaders']['LibraryId']);

        $video = BunnyVideo::get()->byID($data['bunnyVideoId']);
        $this->assertNotNull($video);
        $this->assertSame('new-guid', $video->VideoGuid);
        $this->assertSame('My upload.mp4', $video->Title);
        $this->assertEquals(BunnyStreamClient::STATUS_CREATED, $video->Status);

        $sent = json_decode((string) MockBunnyClient::$history[0]['request']->getBody(), true);
        $this->assertSame(['title' => 'My upload.mp4'], $sent);
    }

    public function testCreateUploadFailsWhenBunnyReturnsNoGuid()
    {
        MockBunnyClient::queue(new Response(200, [], json_encode(['title' => 'x'])));
        $field = $this->makeField(null, ['title' => 'x']);

        try {
            $field->createUpload();
            $this->fail('Expected an HTTP 500');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(500, $e->getResponse()->getStatusCode());
        }
        $this->assertSame(0, BunnyVideo::get()->count(), 'no local record without a remote video');
    }
}
