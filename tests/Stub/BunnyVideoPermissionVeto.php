<?php

namespace Restruct\BunnyStream\Tests\Stub;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Lets a test veto BunnyVideo's permission methods the way a project's extension would, to prove
 * that each of them, and createUpload() through canCreate(), honours extensions.
 * Inert unless $denyCreate is set or a method name is in $deny; the test resets both.
 */
class BunnyVideoPermissionVeto extends Extension implements TestOnly
{
    public static bool $denyCreate = false;

    /**
     * Names of the can*() methods to veto, e.g. ['canDelete'].
     */
    public static array $deny = [];

    public function canCreate($member = null, $context = [])
    {
        # null = no opinion, so the record's own check decides
        return static::$denyCreate || in_array('canCreate', static::$deny, true) ? false : null;
    }

    public function canView($member = null)
    {
        return in_array('canView', static::$deny, true) ? false : null;
    }

    public function canEdit($member = null)
    {
        return in_array('canEdit', static::$deny, true) ? false : null;
    }

    public function canDelete($member = null)
    {
        return in_array('canDelete', static::$deny, true) ? false : null;
    }
}
