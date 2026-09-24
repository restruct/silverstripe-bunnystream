<?php

namespace Restruct\BunnyStream\Tests;

use Restruct\BunnyStream\Forms\BunnyUploadField;
use Restruct\BunnyStream\Tests\Stub\UploadTestController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\View\Requirements;

/**
 * Regression test for the hidden input's name attribute in BunnyUploadField::Field(): the name is
 * escaped like every other value echoed into the markup (commit "BunnyUploadField: escape the
 * field name and value in the markup"). Kept in its own class so the existing field tests stay
 * as they were written.
 */
class BunnyUploadFieldNameEscapingTest extends SapphireTest
{
    # The field looks up a BunnyVideo by its value, so the ORM tables must exist
    protected $usesDatabase = true;

    protected ?UploadTestController $controller = null;

    protected function setUp(): void
    {
        parent::setUp();
        Requirements::clear();
    }

    protected function tearDown(): void
    {
        # Pop the controller this test pushed, so the stack is as the next test expects it
        if ($this->controller) {
            $this->controller->popCurrent();
            $this->controller = null;
        }
        Requirements::clear();
        parent::tearDown();
    }

    public function testHiddenInputNameIsEscaped()
    {
        # Same setup as BunnyUploadFieldTest::makeField(): a form on a current controller, so the
        # field can build its createUpload link
        $request = new HTTPRequest('GET', 'bunnytest/Form');
        $request->setSession(new Session([]));
        $this->controller = UploadTestController::create();
        $this->controller->setRequest($request);
        $this->controller->pushCurrent();

        # A double quote in the name would close the attribute early if it were echoed raw
        $field = BunnyUploadField::create('Video"Id', 'Video');
        Form::create($this->controller, 'Form', FieldList::create($field), FieldList::create());

        $html = (string) $field->Field();

        $this->assertStringContainsString('<input type="hidden" name="Video&quot;Id"', $html);
        $this->assertStringNotContainsString('name="Video"Id"', $html);
    }
}
