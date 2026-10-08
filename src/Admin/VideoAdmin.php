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
    private static $menu_icon_class = 'font-icon-block-media';
    private static $menu_priority = -1;

    private static $managed_models = [
        BunnyVideo::class,
    ];

    private static $required_permission_codes = [
        'CMS_ACCESS_LeftAndMain',
    ];

    # No CSV import. Its "Replace data" option (EmptyBeforeImport) calls removeAll() on the list,
    # which runs BunnyVideo::onBeforeDelete() per record and so deletes every video on Bunny, and
    # since #6 every section editor (not only ADMIN) may delete. A BunnyVideo also cannot be made
    # from CSV: its GUID comes from Bunny when the upload is created. Public property (not config)
    # on ModelAdmin in admin 2 and admin 3 alike.
    public $showImportForm = false;
}
