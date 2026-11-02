<?php

namespace Restruct\BnBrowser;

use SilverStripe\Admin\ModelAdmin;

/**
 * BROWSER-TEST FIXTURE ONLY - the "Lessons" section of the README screenshots:
 * /admin/bnb-showcase/lessons. Opening it seeds the showcase content (BnBShowcaseLesson::seed()),
 * which also replaces the videos the module's own VideoAdmin (/admin/videos) lists.
 */
class BnBShowcaseAdmin extends ModelAdmin
{
    private static $url_segment = 'bnb-showcase';

    private static $menu_title = 'Lessons';

    # Keyed managed_models (SS5 and SS6): 'lessons' becomes the URL segment.
    private static $managed_models = [
        'lessons' => [
            'dataClass' => BnBShowcaseLesson::class,
            'title' => 'Lessons',
        ],
    ];

    protected function init()
    {
        parent::init();
        # Idempotent: a no-op once the showcase content is in place.
        BnBShowcaseLesson::seed();
    }
}
