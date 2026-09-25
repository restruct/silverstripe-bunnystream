<?php

namespace Restruct\BunnyStream\Tests\Stub;

use Restruct\BunnyStream\Forms\BunnyUploadField;
use SilverStripe\Control\Controller;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;

/**
 * A minimal controller hosting a form with a BunnyUploadField, so the field has a URL to build
 * on and its createUpload action can be reached through real request routing.
 */
class UploadTestController extends Controller implements TestOnly
{
    private static $url_segment = 'bunnytest';

    private static $allowed_actions = [
        'Form',
    ];

    public function Form()
    {
        return Form::create(
            $this,
            'Form',
            FieldList::create(BunnyUploadField::create('BunnyVideoID', 'Video')),
            FieldList::create()
        );
    }
}
