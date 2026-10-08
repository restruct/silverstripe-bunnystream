<?php

namespace Restruct\BunnyStream\Tests;

use GuzzleHttp\Psr7\Response;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Forms\BunnyUploadField;
use Restruct\BunnyStream\Model\BunnyVideo;
use Restruct\BunnyStream\Tests\Stub\BunnyVideoPermissionVeto;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use Restruct\BunnyStream\Tests\Stub\UploadTestController;
use Restruct\BunnyStream\Tests\Stub\VideoHolder;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use Restruct\BunnyStream\Admin\VideoAdmin;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Issue #6: BunnyVideo had no permission methods, so the DataObject defaults (ADMIN only) applied,
 * while VideoAdmin admits any member with CMS_ACCESS_LeftAndMain. An editor could open the Videos
 * section but not create, edit or delete a video. The methods now ask for exactly what opening
 * VideoAdmin asks for (its required_permission_codes), not for any CMS access: a Pages-only editor
 * must not delete videos, as that deletes them on Bunny too.
 */
class BunnyVideoPermissionsTest extends FunctionalTest
{
    protected $usesDatabase = true;

    # getCMSFields() lists usages by scanning every has_one to BunnyVideo, which includes this
    # TestOnly stub, so its table must exist
    protected static $extra_dataobjects = [
        VideoHolder::class,
    ];

    protected static $required_extensions = [
        BunnyVideo::class => [BunnyVideoPermissionVeto::class],
    ];

    protected ?UploadTestController $controller = null;

    protected function setUp(): void
    {
        parent::setUp();
        MockBunnyClient::reset();
        BunnyVideoPermissionVeto::$denyCreate = false;
        Injector::inst()->load([BunnyStreamClient::class => ['class' => MockBunnyClient::class]]);
    }

    protected function tearDown(): void
    {
        if ($this->controller) {
            $this->controller->popCurrent();
            $this->controller = null;
        }
        BunnyVideoPermissionVeto::$denyCreate = false;
        MockBunnyClient::reset();
        parent::tearDown();
    }

    /**
     * A member in a group holding exactly these permission codes (not logged in).
     */
    protected function memberWith(string ...$codes): Member
    {
        $group = Group::create(['Title' => 'g-' . implode('-', $codes) . '-' . uniqid()]);
        $group->write();
        foreach ($codes as $code) {
            Permission::grant($group->ID, $code);
        }
        $member = Member::create(['Email' => uniqid() . '@example.com']);
        $member->write();
        $member->Groups()->add($group);
        # Permission caches each member's codes on the first check per member ID; one made while
        # the member had no group (measured: without this reset the codes are not seen) would
        # be stale, so drop the cache
        Permission::reset();
        return $member;
    }

    protected function assertCan(bool $expected, ?Member $member, string $who): void
    {
        $video = BunnyVideo::create(['VideoGuid' => 'g-1', 'Title' => 'Clip']);
        $video->write();
        foreach (['canView', 'canEdit', 'canDelete', 'canCreate'] as $method) {
            $this->assertSame($expected, (bool) $video->$method($member), "$method for $who");
        }
    }

    public function testVideoAdminEditorCanManageVideos()
    {
        # What VideoAdmin's required_permission_codes demands, without ADMIN
        $this->assertCan(true, $this->memberWith('CMS_ACCESS_LeftAndMain'), 'a VideoAdmin editor');
    }

    public function testAdminCanManageVideos()
    {
        # The framework's own helper: a group granted ADMIN through Permission::grant() in
        # memberWith() reads back with no codes (measured), so that helper is not used here
        $this->assertCan(true, $this->createMemberWithPermission('ADMIN'), 'an administrator');
    }

    public function testPagesOnlyEditorCannotManageVideos()
    {
        # Any CMS access is not enough: a delete also deletes the video on Bunny
        $this->assertCan(false, $this->memberWith('CMS_ACCESS_CMSMain'), 'a Pages-only editor');
        $this->assertCan(false, $this->memberWith('CMS_ACCESS_SomeOtherSection'), 'an editor of another section');
    }

    public function testAProjectsRequiredPermissionCodesAreFollowed()
    {
        # A project gives the Videos section its own code: holders of that code manage videos,
        # CMS_ACCESS_LeftAndMain (all sections) still does, and other CMS editors still do not
        VideoAdmin::config()->set('required_permission_codes', ['CMS_ACCESS_BunnyVideos']);
        $this->assertCan(true, $this->memberWith('CMS_ACCESS_BunnyVideos'), 'a holder of the project code');
        $this->assertCan(true, $this->memberWith('CMS_ACCESS_LeftAndMain'), 'a VideoAdmin editor');
        $this->assertCan(false, $this->memberWith('CMS_ACCESS_CMSMain'), 'a Pages-only editor');
    }

    public function testAllOfSeveralRequiredCodesAreNeeded()
    {
        # As LeftAndMain::canView(): with several codes, all of them are required
        VideoAdmin::config()->set('required_permission_codes', ['CMS_ACCESS_BunnyVideos', 'BUNNY_EXTRA']);
        $this->assertCan(false, $this->memberWith('CMS_ACCESS_BunnyVideos'), 'a holder of one of two codes');
        $this->assertCan(true, $this->memberWith('CMS_ACCESS_BunnyVideos', 'BUNNY_EXTRA'), 'a holder of both codes');
    }

    public function testEmptyOrFalseRequiredCodesFallBackToTheClassCode()
    {
        # false would open the section to every logged-in member; the records do not follow that
        foreach ([false, []] as $value) {
            VideoAdmin::config()->set('required_permission_codes', $value);
            $label = var_export($value, true);
            $this->assertCan(true, $this->memberWith('CMS_ACCESS_' . VideoAdmin::class), "the class code with $label");
            $this->assertCan(false, $this->memberWith('SOME_NON_CMS_PERMISSION'), "a non-CMS member with $label");
        }
    }

    public function testMemberWithoutCmsAccessCannot()
    {
        $this->assertCan(false, $this->memberWith('SOME_NON_CMS_PERMISSION'), 'a non-CMS member');
    }

    public function testAnonymousCannot()
    {
        $this->logOut();
        $this->assertCan(false, null, 'an anonymous visitor');
    }

    public function testTheMemberArgumentIsChecked()
    {
        # Logged in as ADMIN, asking about someone else: the answer is about the argument
        $this->logInWithPermission('ADMIN');
        $this->assertCan(false, $this->memberWith('SOME_NON_CMS_PERMISSION'), 'the given member, not the current one');
    }

    public function testVideoAdminEditorCanOpenAndSaveTheEditForm()
    {
        $video = BunnyVideo::create(['VideoGuid' => 'g-1', 'Title' => 'Before', 'Status' => BunnyStreamClient::STATUS_FINISHED]);
        $video->write();
        $this->logInAs($this->memberWith('CMS_ACCESS_LeftAndMain'));

        $segment = str_replace('\\', '-', BunnyVideo::class);
        $editUrl = "admin/videos/$segment/EditForm/field/$segment/item/{$video->ID}/edit";
        $response = $this->get($editUrl);
        $this->assertSame(200, $response->getStatusCode());
        # Editable (canEdit), not a read-only view: the title is a text input and there is a save action
        $this->assertMatchesRegularExpression('#<input[^>]+name="Title"[^>]+type="text"|<input[^>]+type="text"[^>]+name="Title"#', $response->getBody());

        $response = $this->submitForm('Form_ItemEditForm', 'action_doSave', ['Title' => 'After']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('After', BunnyVideo::get()->byID($video->ID)->Title);
    }

    /**
     * createUpload() follows canCreate(), so since #6 a Pages-only editor is refused (security
     * tightening: before, any CMS access was enough).
     */
    public function testCreateUploadRefusesAPagesOnlyEditor()
    {
        $this->logInAs($this->memberWith('CMS_ACCESS_CMSMain'));
        $field = $this->makeUploadField();
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'never'])));

        try {
            $field->createUpload();
            $this->fail('Expected an HTTP 403');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
        $this->assertCount(0, MockBunnyClient::$history, 'nothing sent to Bunny');
    }

    /**
     * A BunnyUploadField in a form on a current controller, with the session's token in the request.
     */
    protected function makeUploadField(): BunnyUploadField
    {
        $request = new HTTPRequest('GET', 'bunnytest/Form/field/BunnyVideoID/createUpload', ['title' => 'Clip.mp4']);
        $request->setSession(new Session([]));
        $this->controller = UploadTestController::create();
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();
        $field = BunnyUploadField::create('BunnyVideoID', 'Video');
        Form::create($this->controller, 'Form', FieldList::create($field), FieldList::create());
        $request['SecurityID'] = $field->getForm()->getSecurityToken()->getValue();
        return $field;
    }

    /**
     * createUpload() now asks BunnyVideo::canCreate() rather than repeating the check, so a project
     * that tightens canCreate() through an extension tightens the upload endpoint with it.
     */
    public function testCreateUploadFollowsCanCreate()
    {
        $this->logInAs($this->memberWith('CMS_ACCESS_LeftAndMain'));
        BunnyVideoPermissionVeto::$denyCreate = true;

        $field = $this->makeUploadField();
        MockBunnyClient::queue(new Response(200, [], json_encode(['guid' => 'never'])));

        try {
            $field->createUpload();
            $this->fail('Expected an HTTP 403');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
        $this->assertCount(0, MockBunnyClient::$history, 'nothing sent to Bunny');
        $this->assertSame(0, BunnyVideo::get()->count());
    }
}
