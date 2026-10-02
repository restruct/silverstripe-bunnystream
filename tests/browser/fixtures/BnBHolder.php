<?php

namespace Restruct\BnBrowser;

use Restruct\BunnyStream\Forms\BunnyUploadField;
use Restruct\BunnyStream\Model\BunnyVideo;
use SilverStripe\ORM\DataObject;

/**
 * BROWSER-TEST FIXTURE ONLY - a record with a has_one to BunnyVideo and a BunnyUploadField for it,
 * set up as the README's usage example does.
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * Written to load on both Silverstripe 5 and 6.
 *
 * Every dev/build (the runner does one per run) wipes and re-seeds the holders and the videos.
 * Nothing here reaches Bunny: the seeded videos are "finished" (so opening one never refreshes it
 * from the API) and are deleted with their GUID cleared first (so the delete stays local).
 * Thumbnails and the player iframe point at Bunny's hosts; the specs answer those requests
 * themselves (support.ts).
 *
 * @property string $Title
 * @method BunnyVideo BunnyVideo()
 */
class BnBHolder extends DataObject
{
    private static $table_name = 'BnBHolder';

    private static $singular_name = 'Browser Holder';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $has_one = [
        'BunnyVideo' => BunnyVideo::class,
    ];

    private static $summary_fields = [
        'Title' => 'Title',
    ];

    /** Seeded videos: title => [GUID, duration s, width, height, bytes]. All status "finished". */
    public const VIDEOS = [
        'Ready video' => ['0b0b0b0b-1111-4111-8111-000000000001', 125, 1920, 1080, 52428800],
        'Spare video' => ['0b0b0b0b-2222-4222-8222-000000000002', 61, 1280, 720, 10485760],
        'Options video' => ['0b0b0b0b-3333-4333-8333-000000000003', 30, 640, 360, 1048576],
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

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

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
        foreach (self::VIDEOS as $title => [$guid, $duration, $width, $height, $size]) {
            $video = BunnyVideo::create([
                'Title' => $title,
                'VideoGuid' => $guid,
                'Status' => 4, # BunnyStreamClient::STATUS_FINISHED
                'Duration' => $duration,
                'Width' => $width,
                'Height' => $height,
                'StorageSize' => $size,
            ]);
            $video->write();
            $ids[$title] = $video->ID;
        }

        # One holder per spec that writes, so specs never share a row.
        static::create(['Title' => 'With video', 'BunnyVideoID' => $ids['Ready video']])->write();
        static::create(['Title' => 'Unlink target', 'BunnyVideoID' => $ids['Ready video']])->write();
        static::create(['Title' => 'Upload target'])->write();
        static::create(['Title' => 'Refused upload'])->write();
    }
}
