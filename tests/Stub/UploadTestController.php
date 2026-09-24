<?php

namespace Restruct\BunnyStream\Tests\Stub;

use SilverStripe\Control\Controller;
use SilverStripe\Dev\TestOnly;

/**
 * A minimal controller to host a form, so BunnyUploadField::Link() has a URL to build on.
 */
class UploadTestController extends Controller implements TestOnly
{
    private static $url_segment = 'bunnytest';
}
