<?php

namespace Restruct\BunnyStream\Admin;

use Restruct\BunnyStream\Model\BunnyVideo;
use SilverStripe\Admin\ModelAdmin;

/**
 * CMS admin for managing uploaded videos.
 * Provides an overview of all BunnyVideo records with status, title, duration.
 */
class VideoAdmin extends ModelAdmin
{
    private static $url_segment = 'videos';
    private static $menu_title = 'Video\'s';
    private static $menu_icon_class = 'font-icon-play-circle';
    private static $menu_priority = -1;

    private static $managed_models = [
        BunnyVideo::class,
    ];

    private static $required_permission_codes = [
        'CMS_ACCESS_LeftAndMain',
    ];
}
