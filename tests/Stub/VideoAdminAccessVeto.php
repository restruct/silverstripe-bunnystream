<?php

namespace Restruct\BunnyStream\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * An alternateAccessCheck() on VideoAdmin, the hook LeftAndMain::canView() (admin 2) and
 * AdminController::canView() (admin 3) consult first, to prove BunnyVideo's permissions honour it.
 * Returns $result (null = inert); the test resets it.
 */
class VideoAdminAccessVeto extends Extension implements TestOnly
{
    public static ?bool $result = null;

    public function alternateAccessCheck($member = null)
    {
        return static::$result;
    }
}
