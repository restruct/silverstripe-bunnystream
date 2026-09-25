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
use SilverStripe\Security\SecurityToken;
use SilverStripe\View\Requirements;

/**
 * Issue #7: createUpload() creates a video on Bunny (storage costs money) and a local record, so
 * it demands a CMS user (Permission 'CMS_ACCESS') and the form's CSRF token. These tests call the
 * action directly, under plain SapphireTest, where SecurityToken is enabled.
 */
class BunnyUploadFieldAccessTest extends SapphireTest
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
     * A field inside a form on a controller that is current. $token: true sends the session's
     * real token as SecurityID, a string sends that string, null sends none.
     */
    protected function makeField($token = true): BunnyUploadField
    {
        $request = new HTTPRequest('GET', 'bunnytest/Form/field/BunnyVideoID/createUpload', ['title' => 'Clip.mp4']);
        $request->setSession(new Session([]));
        $this->controller = UploadTestController::create();
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();

        $field = BunnyUploadField::create('BunnyVideoID', 'Video');
        Form::create($this->controller, 'Form', FieldList::create($field), FieldList::create());

        # The token lives in the session, so it can only be read once the controller (and its
        # request with the session) is current; it is then added to the request's GET vars.
        if ($token === true) {
            $request['SecurityID'] = $field->getForm()->getSecurityToken()->getValue();
        } elseif (is_string($token)) {
            # Make sure the session holds a real token, so a mismatch is what is being tested
            $field->getForm()->getSecurityToken()->getValue();
            $request['SecurityID'] = $token;
        }
        return $field;
    }

    /**
     * Calls createUpload() and returns the HTTP status it answered with (200 when it returned).
     */
    protected function statusOf(BunnyUploadField $field): int
    {
        try {
            return $field->createUpload()->getStatusCode();
        } catch (HTTPResponse_Exception $e) {
            return $e->getResponse()->getStatusCode();
        }
    }

    public function testFieldRendersTheSessionToken()
    {
        $field = $this->makeField(null);
        $token = $field->getForm()->getSecurityToken()->getValue();
        $this->assertNotEmpty($token);

        $html = (string) $field->Field();

        # The JS reads this attribute and sends it back as SecurityID
        $this->assertStringContainsString('data-security-token="' . htmlspecialchars($token) . '"', $html);
    }

    public function testMissingTokenIsRefused()
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'should-not-exist'])));

        $this->assertSame(400, $this->statusOf($this->makeField(null)));
        $this->assertSame(0, BunnyVideo::get()->count(), 'no local record');
        $this->assertCount(0, MockBunnyClient::$history, 'nothing sent to Bunny');
    }

    public function testWrongTokenIsRefused()
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'should-not-exist'])));

        $this->assertSame(400, $this->statusOf($this->makeField('not-the-token')));
        $this->assertSame(0, BunnyVideo::get()->count(), 'no local record');
        $this->assertCount(0, MockBunnyClient::$history, 'nothing sent to Bunny');
    }

    public function testAnonymousIsRefused()
    {
        $this->logOut();
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'should-not-exist'])));

        $this->assertSame(403, $this->statusOf($this->makeField()));
        $this->assertSame(0, BunnyVideo::get()->count(), 'no local record');
        $this->assertCount(0, MockBunnyClient::$history, 'nothing sent to Bunny');
    }

    public function testMemberWithoutCmsAccessIsRefused()
    {
        # Logged in, valid token, but no CMS_ACCESS_* code and no ADMIN
        $this->logInWithPermission('SOME_NON_CMS_PERMISSION');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'should-not-exist'])));

        $this->assertSame(403, $this->statusOf($this->makeField()));
        $this->assertSame(0, BunnyVideo::get()->count(), 'no local record');
        $this->assertCount(0, MockBunnyClient::$history, 'nothing sent to Bunny');
    }

    public function testMemberWithAccessToSomeOtherCmsSectionIsAllowed()
    {
        # Only one specific section's code (not LeftAndMain, not VideoAdmin, not ADMIN): the field
        # can sit in that section's edit form, so its editors must be able to upload.
        $this->logInWithPermission('CMS_ACCESS_SomeOtherSection');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'section-guid'])));

        $response = $this->makeField()->createUpload();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('section-guid', $data['videoGuid']);
        $this->assertSame(1, BunnyVideo::get()->count());
    }

    public function testCmsUserWithValidTokenIsAllowed()
    {
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'ok-guid'])));

        $response = $this->makeField()->createUpload();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('ok-guid', $data['videoGuid']);
        $this->assertSame('Clip.mp4', BunnyVideo::get()->byID($data['bunnyVideoId'])->Title);
    }

    public function testTokensDisabledSiteWideNeedNoToken()
    {
        # SecurityToken::disable() swaps in a NullSecurityToken: no token is rendered and none is
        # demanded, the same as for form submissions. The permission check still applies.
        SecurityToken::disable();
        try {
            $this->logInWithPermission('CMS_ACCESS_LeftAndMain');
            MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'no-token-guid'])));
            $field = $this->makeField(null);

            $this->assertStringContainsString('data-security-token=""', (string) $field->Field());
            $this->assertSame(200, $this->statusOf($field));
        } finally {
            SecurityToken::enable();
        }
    }
}
