<?php

namespace Restruct\BunnyStream\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Lets a test veto BunnyVideo::canCreate() the way a project's extension would, to prove that
 * BunnyVideo's permission methods, and createUpload() through canCreate(), honour extensions.
 * Inert unless $denyCreate is set; the test resets it.
 */
class BunnyVideoPermissionVeto extends Extension implements TestOnly
{
    public static bool $denyCreate = false;

    public function canCreate($member = null, $context = [])
    {
        # null = no opinion, so the record's own check decides
        return static::$denyCreate ? false : null;
    }
}
