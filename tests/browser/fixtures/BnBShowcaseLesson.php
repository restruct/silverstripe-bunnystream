<?php

namespace Restruct\BnBrowser;

use Restruct\BunnyStream\Forms\BunnyUploadField;
use Restruct\BunnyStream\Model\BunnyVideo;
use SilverStripe\ORM\DataObject;

/**
 * BROWSER-TEST FIXTURE ONLY - demo content for the README screenshots
 * (tests/browser/screenshots/readme.shot.ts): a "Lesson" with a has_one to BunnyVideo and a
 * BunnyUploadField for it, exactly as the README's usage example sets it up.
 *
 * Never loaded by a real install (tests/browser/ carries a _manifest_exclude marker; the runner
 * copies the fixtures into a scratch host's app/). Written to load on both Silverstripe 5 and 6.
 *
 * Seeding is NOT done in requireDefaultRecords(): BnBHolder wipes every BunnyVideo there, and the
 * order in which dev/build calls the two classes is not ours to rely on. BnBShowcaseAdmin::init()
 * calls seed() instead, the first time /admin/bnb-showcase is opened. seed() replaces ALL videos
 * with the showcase ones, so the browser specs need a fresh dev/build after a screenshot run (the
 * runner does one on every run).
 *
 * Nothing here reaches Bunny: the GUIDs are made up, and every video the screenshots open is
 * "finished", so opening it never refreshes it from the API. The one video that is still
 * transcoding is only shown in the list (opening it would ask the API for its status).
 *
 * @property string $Title
 * @method BunnyVideo BunnyVideo()
 */
class BnBShowcaseLesson extends DataObject
{
    private static $table_name = 'BnBShowcaseLesson';

    private static $singular_name = 'Lesson';

    private static $plural_name = 'Lessons';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $has_one = [
        'BunnyVideo' => BunnyVideo::class,
    ];

    private static $summary_fields = [
        'Title' => 'Title',
    ];

    /**
     * Showcase videos: title => [GUID, status, duration s, width, height, bytes, encode %].
     * The GUIDs are obviously fake (5a5a5a5a-...); seed() uses the first one to tell whether the
     * showcase is already in place.
     */
    public const VIDEOS = [
        'Welcome to the course' => ['5a5a5a5a-0001-4000-8000-000000000001', 4, 102, 1920, 1080, 88080384, 100],
        'Setting up your workspace' => ['5a5a5a5a-0002-4000-8000-000000000002', 4, 375, 1920, 1080, 327155712, 100],
        'Working with templates' => ['5a5a5a5a-0003-4000-8000-000000000003', 4, 668, 2560, 1440, 1503238554, 100],
        'Keyboard shortcuts in two minutes' => ['5a5a5a5a-0004-4000-8000-000000000004', 4, 118, 1280, 720, 31457280, 100],
        # Status 3 = BunnyStreamClient::STATUS_TRANSCODING. Listed only, never opened (see above).
        'Live Q&A recording' => ['5a5a5a5a-0005-4000-8000-000000000005', 3, 0, 0, 0, 0, 40],
    ];

    /** Lessons: title => video title (null: no video yet, so the field shows its upload controls). */
    public const LESSONS = [
        'Lesson 1: Getting started' => 'Welcome to the course',
        'Lesson 2: Your workspace' => 'Setting up your workspace',
        'Lesson 3: Templates' => null,
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->replaceField(
            'BunnyVideoID',
            BunnyUploadField::create('BunnyVideoID', 'Video')->setDescription('MP4, MOV, ...')
        );
        return $fields;
    }

    /**
     * Replace every video and lesson with the showcase content, unless it is already there.
     */
    public static function seed(): void
    {
        $firstGuid = array_values(self::VIDEOS)[0][0];
        if (BunnyVideo::get()->filter('VideoGuid', $firstGuid)->exists()) {
            return;
        }

        foreach (static::get() as $old) {
            $old->delete();
        }
        foreach (BunnyVideo::get() as $old) {
            # Without a GUID the module deletes locally only (BunnyVideo::onBeforeDelete).
            $old->VideoGuid = '';
            $old->write();
            $old->delete();
        }

        $ids = [];
        foreach (self::VIDEOS as $title => [$guid, $status, $duration, $width, $height, $size, $progress]) {
            $video = BunnyVideo::create([
                'Title' => $title,
                'VideoGuid' => $guid,
                'Status' => $status,
                'Duration' => $duration,
                'Width' => $width,
                'Height' => $height,
                'StorageSize' => $size,
                'EncodeProgress' => $progress,
            ]);
            $video->write();
            $ids[$title] = $video->ID;
        }

        foreach (self::LESSONS as $title => $videoTitle) {
            static::create([
                'Title' => $title,
                'BunnyVideoID' => $videoTitle ? $ids[$videoTitle] : 0,
            ])->write();
        }
    }
}
