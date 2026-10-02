<?php

namespace Restruct\BnBrowser;

use SilverStripe\Admin\ModelAdmin;

/**
 * BROWSER-TEST FIXTURE ONLY - the CMS screen for the upload-field specs: /admin/bnb-browser/holders
 * (see BnBHolder for why this never loads in a real install). The module's own VideoAdmin
 * (/admin/videos) lists the seeded videos.
 */
class BnBAdmin extends ModelAdmin
{
    private static $url_segment = 'bnb-browser';

    private static $menu_title = 'BunnyStream browser test';

    # Keyed managed_models (SS5 and SS6): 'holders' becomes the URL segment.
    private static $managed_models = [
        'holders' => [
            'dataClass' => BnBHolder::class,
            'title' => 'Holders',
        ],
    ];
}
