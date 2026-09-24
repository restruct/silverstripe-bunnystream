<?php

namespace Restruct\BunnyStream\Tests\Stub;

use Restruct\BunnyStream\Model\BunnyVideo;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record that points at a BunnyVideo, the way a consuming project's model would.
 * Used to check BunnyVideo::getUsages() finds it without knowing the class in advance.
 *
 * Deliberately concrete: an abstract DataObject in a module's tests/ fatals the temp-database
 * build of every consuming project (TableBuilder instantiates every DataObject class).
 */
class VideoHolder extends DataObject implements TestOnly
{
    private static $table_name = 'BunnyTest_VideoHolder';

    private static $db = [
        'Title' => 'Varchar',
    ];

    private static $has_one = [
        'Video' => BunnyVideo::class,
    ];
}
