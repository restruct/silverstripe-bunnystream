<?php

namespace Restruct\BunnyStream\Tests;

use GuzzleHttp\Psr7\Response;
use Restruct\BunnyStream\Api\BunnyStreamClient;
use Restruct\BunnyStream\Model\BunnyVideo;
use Restruct\BunnyStream\Tests\Stub\MockBunnyClient;
use Restruct\BunnyStream\Tests\Stub\VideoHolder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * BunnyVideo: schema, formatting, player options and embed markup, API sync, usage discovery,
 * CMS fields, and the fail-closed delete flow. All Bunny traffic goes to MockBunnyClient.
 */
class BunnyVideoTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [
        VideoHolder::class,
    ];

    /** @var Controller|null Controller pushed by pushControllerWithSession(), popped in tearDown */
    protected ?Controller $pushedController = null;

    protected function setUp(): void
    {
        parent::setUp();
        MockBunnyClient::reset();
        # Every BunnyStreamClient::create() in the module now gets the mock transport
        Injector::inst()->load([BunnyStreamClient::class => ['class' => MockBunnyClient::class]]);
    }

    protected function tearDown(): void
    {
        if ($this->pushedController) {
            $this->pushedController->popCurrent();
            $this->pushedController = null;
        }
        MockBunnyClient::reset();
        parent::tearDown();
    }

    /**
     * The exception class a failed remote delete must throw: it moved namespace in framework 6.
     */
    protected static function validationExceptionClass(): string
    {
        return class_exists('SilverStripe\\Core\\Validation\\ValidationException')
            ? 'SilverStripe\\Core\\Validation\\ValidationException'
            : 'SilverStripe\\ORM\\ValidationException';
    }

    /**
     * Put a controller with a request and session on the stack, as a CMS request would.
     */
    protected function pushControllerWithSession(): Session
    {
        $session = new Session([]);
        $request = new HTTPRequest('GET', '/');
        $request->setSession($session);
        $controller = Controller::create();
        $controller->setRequest($request);
        $controller->pushCurrent();
        $this->pushedController = $controller;
        return $session;
    }

    protected function makeVideo(array $data = []): BunnyVideo
    {
        $video = BunnyVideo::create(array_merge(['VideoGuid' => 'guid-1', 'Title' => 'Clip'], $data));
        $video->write();
        return $video;
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public function testSchema()
    {
        $fields = DataObject::getSchema()->databaseFields(BunnyVideo::class, false);
        foreach (['VideoGuid', 'Title', 'Description', 'Status', 'Duration', 'Width', 'Height', 'EncodeProgress', 'StorageSize', 'PlayerOptions', 'PosterImageID'] as $name) {
            $this->assertArrayHasKey($name, $fields, "BunnyVideo.$name is in the schema");
        }
        $this->assertSame('BunnyVideo', DataObject::getSchema()->tableName(BunnyVideo::class));
    }

    /**
     * Regression: StorageSize was an Int (signed 32 bit), so any video over 2 GiB could not be
     * stored. On Silverstripe 6 the write throws (field validation), which getCMSFields() swallows,
     * so the record silently stopped syncing; on 5 MySQL clamps it to 2147483647.
     */
    public function testStorageSizeAboveTwoGibIsStored()
    {
        $bytes = 5 * 1024 * 1024 * 1024; // 5 GiB
        $video = $this->makeVideo(['StorageSize' => $bytes]);

        $reloaded = BunnyVideo::get()->byID($video->ID);
        $this->assertEquals($bytes, $reloaded->StorageSize);
        $this->assertSame('5.00 GB', $reloaded->getStorageSizeFormatted());
    }

    // -------------------------------------------------------------------------
    // Status and formatting
    // -------------------------------------------------------------------------

    public function testStatusLabelsAndReadiness()
    {
        $expected = [
            BunnyStreamClient::STATUS_CREATED => 'Aangemaakt',
            BunnyStreamClient::STATUS_UPLOADED => 'Geüpload',
            BunnyStreamClient::STATUS_PROCESSING => 'Verwerken...',
            BunnyStreamClient::STATUS_TRANSCODING => 'Transcoding...',
            BunnyStreamClient::STATUS_FINISHED => 'Gereed',
            BunnyStreamClient::STATUS_ERROR => 'Fout',
            BunnyStreamClient::STATUS_UPLOAD_FAILED => 'Upload mislukt',
            99 => 'Onbekend',
        ];
        foreach ($expected as $status => $label) {
            $video = BunnyVideo::create(['Status' => $status]);
            $this->assertSame($label, $video->getStatusLabel(), "status $status");
            $this->assertSame($status === BunnyStreamClient::STATUS_FINISHED, $video->isReady(), "isReady for $status");
        }
    }

    public function testReadinessSurvivesADatabaseRoundTrip()
    {
        $video = $this->makeVideo(['Status' => BunnyStreamClient::STATUS_FINISHED]);
        $this->assertTrue(BunnyVideo::get()->byID($video->ID)->isReady());
    }

    public function testFormatting()
    {
        $video = BunnyVideo::create([
            'Duration' => 125,
            'StorageSize' => 3 * 1024 * 1024 / 2, // 1.5 MB
            'Width' => 1920,
            'Height' => 1080,
        ]);
        $this->assertSame('2:05', $video->getDurationFormatted());
        $this->assertSame('1.5 MB', $video->getStorageSizeFormatted());
        $this->assertSame('1920 × 1080', $video->getDimensionsFormatted());

        # Empty values format to an empty string rather than 0:00 / 0.0 MB
        $empty = BunnyVideo::create();
        $this->assertSame('', $empty->getDurationFormatted());
        $this->assertSame('', $empty->getStorageSizeFormatted());
        $this->assertSame('', $empty->getDimensionsFormatted());
    }

    public function testTitleFallsBackToGuid()
    {
        $this->assertSame('My clip', BunnyVideo::create(['Title' => 'My clip', 'VideoGuid' => 'g'])->getTitle());
        $this->assertSame('g', BunnyVideo::create(['VideoGuid' => 'g'])->getTitle());
        $this->assertSame('(geen video)', BunnyVideo::create()->getTitle());
    }

    public function testThumbnailImg()
    {
        $this->assertSame('', (string) BunnyVideo::create()->getThumbnailIMG()->getValue());

        $html = (string) BunnyVideo::create(['VideoGuid' => 'g-9'])->getThumbnailIMG()->getValue();
        $this->assertStringContainsString(
            'src="https://vz-' . MockBunnyClient::LIBRARY_ID . '.b-cdn.net/g-9/thumbnail.jpg"',
            $html
        );
    }

    // -------------------------------------------------------------------------
    // Player options (JSON store)
    // -------------------------------------------------------------------------

    public function testPlayerOptionsDefaults()
    {
        $video = BunnyVideo::create();
        $this->assertSame([], $video->getPlayerOptionsData());
        $this->assertFalse($video->getEnforceFullWatch());
        # Default true, matching the library-level player configuration
        $this->assertTrue($video->getRememberPosition());
        $this->assertSame('', $video->getStartTime());
        $this->assertSame('fallback', $video->getPlayerOption('missing', 'fallback'));
    }

    public function testInvalidPlayerOptionsJsonIsTreatedAsEmpty()
    {
        $video = BunnyVideo::create(['PlayerOptions' => '{not json']);
        $this->assertSame([], $video->getPlayerOptionsData());

        # Valid JSON that is not an object is not an options array either
        $video = BunnyVideo::create(['PlayerOptions' => '"just a string"']);
        $this->assertSame([], $video->getPlayerOptionsData());
    }

    public function testPlayerOptionsRoundTripThroughTheJsonBlob()
    {
        $video = $this->makeVideo();
        # Assigned as properties, the way a CMS form save reaches the set<Field> accessors
        $video->EnforceFullWatch = 1;
        $video->RememberPosition = 0;
        $video->StartTime = ' 1m30s ';
        $video->write();

        $reloaded = BunnyVideo::get()->byID($video->ID);
        $this->assertSame(
            ['enforceFullWatch' => true, 'rememberPosition' => false, 't' => '1m30s'],
            json_decode($reloaded->PlayerOptions, true)
        );
        $this->assertTrue($reloaded->getEnforceFullWatch());
        $this->assertFalse($reloaded->getRememberPosition());
        $this->assertSame('1m30s', $reloaded->getStartTime());
    }

    public function testSettingAnOptionToNullRemovesIt()
    {
        $video = BunnyVideo::create();
        $video->setPlayerOption('a', 1);
        $video->setPlayerOption('b', 2);
        $video->setPlayerOption('a', null);
        $this->assertSame(['b' => 2], $video->getPlayerOptionsData());

        # Clearing the last option leaves no JSON at all
        $video->setPlayerOption('b', null);
        $this->assertNull($video->getField('PlayerOptions'));

        # A blank start time is removed rather than stored as ''
        $video->setStartTime('   ');
        $this->assertArrayNotHasKey('t', $video->getPlayerOptionsData());
    }

    public function testPlayerQueryParamsAndDataAttributes()
    {
        $video = BunnyVideo::create();
        $this->assertSame(['rememberPosition' => 'true'], $video->getPlayerQueryParams());
        $this->assertSame([], $video->getPlayerDataAttributes());

        $video->setRememberPosition(false);
        $video->setStartTime('90s');
        $video->setEnforceFullWatch(true);
        $this->assertSame(['rememberPosition' => 'false', 't' => '90s'], $video->getPlayerQueryParams());
        $this->assertSame(['data-enforce-full-watch' => '1'], $video->getPlayerDataAttributes());
    }

    // -------------------------------------------------------------------------
    // Embed markup
    // -------------------------------------------------------------------------

    public function testPlayerIframeIsEmptyWithoutAVideo()
    {
        $this->assertSame('', BunnyVideo::create()->getPlayerIframeHTML());
    }

    public function testPlayerIframeDefaults()
    {
        $html = BunnyVideo::create(['VideoGuid' => 'g-1'])->getPlayerIframeHTML();

        $base = 'https://iframe.mediadelivery.net/embed/' . MockBunnyClient::LIBRARY_ID . '/g-1';
        # autoplay is always explicit (false) so a library-level autoplay setting is overridden
        $this->assertStringContainsString('src="' . $base . '?autoplay=false&amp;rememberPosition=true"', $html);
        $this->assertStringContainsString('<div class="ratio ratio-16x9">', $html);
        $this->assertStringNotContainsString('data-enforce-full-watch', $html);
    }

    public function testPlayerIframeOptions()
    {
        $video = BunnyVideo::create(['VideoGuid' => 'g-1']);
        $video->setStartTime('1h2m3s');
        $video->setEnforceFullWatch(true);

        $html = $video->getPlayerIframeHTML(['autoplay' => true, 'muted' => true, 'loop' => true, 'controls' => false]);

        $this->assertStringContainsString('?autoplay=true&amp;muted=true&amp;loop=true&amp;controls=false&amp;rememberPosition=true&amp;t=1h2m3s"', $html);
        $this->assertStringContainsString(' data-enforce-full-watch="1"', $html);
    }

    public function testPlayerIframeAppendsToASignedUrl()
    {
        Injector::inst()->load([BunnyStreamClient::class => [
            'class' => MockBunnyClient::class,
            'constructor' => [null, null, null, 'token-key'],
        ]]);

        $html = BunnyVideo::create(['VideoGuid' => 'g-1'])->getPlayerIframeHTML();

        # The token query string is already there, so the player params follow with &, not a second ?
        $this->assertMatchesRegularExpression('#/g-1\?token=[0-9a-f]{64}&amp;expires=\d+&amp;autoplay=false&amp;rememberPosition=true"#', $html);
    }

    public function testPlayerIframeEscapesTheStartTime()
    {
        $video = BunnyVideo::create(['VideoGuid' => 'g-1']);
        $video->setStartTime('"><script>');
        $html = $video->getPlayerIframeHTML();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('t=%22%3E%3Cscript%3E', $html);
    }

    // -------------------------------------------------------------------------
    // Sync from API
    // -------------------------------------------------------------------------

    public function testRefreshFromApiUpdatesAndWrites()
    {
        $video = $this->makeVideo(['Status' => BunnyStreamClient::STATUS_UPLOADED]);
        MockBunnyClient::queue(new Response(200, [], json_encode([
            'title' => 'From Bunny',
            'status' => BunnyStreamClient::STATUS_FINISHED,
            'length' => 61,
            'width' => 1280,
            'height' => 720,
            'encodeProgress' => 100,
            'storageSize' => 1234567,
        ])));

        $video->refreshFromApi();

        $request = MockBunnyClient::$history[0]['request'];
        $this->assertSame('/library/' . MockBunnyClient::LIBRARY_ID . '/videos/guid-1', $request->getUri()->getPath());

        $reloaded = BunnyVideo::get()->byID($video->ID);
        $this->assertSame('From Bunny', $reloaded->Title);
        $this->assertTrue($reloaded->isReady());
        $this->assertEquals(61, $reloaded->Duration);
        $this->assertEquals(1280, $reloaded->Width);
        $this->assertEquals(720, $reloaded->Height);
        $this->assertEquals(100, $reloaded->EncodeProgress);
        $this->assertEquals(1234567, $reloaded->StorageSize);
    }

    public function testRefreshFromApiKeepsValuesTheApiOmits()
    {
        $video = $this->makeVideo(['Duration' => 30]);
        MockBunnyClient::queue(new Response(200, [], json_encode(['status' => BunnyStreamClient::STATUS_PROCESSING])));

        $video->refreshFromApi();

        $this->assertSame('Clip', $video->Title);
        $this->assertEquals(30, $video->Duration);
        $this->assertEquals(BunnyStreamClient::STATUS_PROCESSING, $video->Status);
    }

    public function testRefreshFromApiDoesNothingWithoutAGuid()
    {
        BunnyVideo::create()->refreshFromApi();
        $this->assertCount(0, MockBunnyClient::$history);
    }

    // -------------------------------------------------------------------------
    // Usages
    // -------------------------------------------------------------------------

    public function testUsagesFindsRecordsPointingAtTheVideo()
    {
        $video = $this->makeVideo();
        $other = $this->makeVideo(['VideoGuid' => 'guid-2']);
        VideoHolder::create(['Title' => 'A', 'VideoID' => $video->ID])->write();
        VideoHolder::create(['Title' => 'B', 'VideoID' => $video->ID])->write();
        VideoHolder::create(['Title' => 'C', 'VideoID' => $other->ID])->write();

        $usages = $video->getUsages();

        $this->assertCount(1, $usages);
        $this->assertSame(VideoHolder::class, $usages[0]['ClassName']);
        $this->assertSame('Video', $usages[0]['RelationName']);
        $this->assertEqualsCanonicalizing(['A', 'B'], $usages[0]['Records']->column('Title'));
    }

    public function testUsagesIsEmptyForAnUnsavedOrUnusedVideo()
    {
        $this->assertSame([], BunnyVideo::create()->getUsages());
        $this->assertSame([], $this->makeVideo()->getUsages());
    }

    // -------------------------------------------------------------------------
    // CMS fields
    // -------------------------------------------------------------------------

    public function testCmsFieldsForAReadyVideo()
    {
        $video = $this->makeVideo(['Status' => BunnyStreamClient::STATUS_FINISHED]);

        $fields = $video->getCMSFields();

        # A finished video is not re-synced
        $this->assertCount(0, MockBunnyClient::$history);

        $this->assertInstanceOf(TextField::class, $fields->dataFieldByName('Title'));
        $this->assertInstanceOf(TextareaField::class, $fields->dataFieldByName('Description'));
        $this->assertInstanceOf(CheckboxField::class, $fields->dataFieldByName('EnforceFullWatch'));
        $this->assertInstanceOf(CheckboxField::class, $fields->dataFieldByName('RememberPosition'));
        $this->assertInstanceOf(TextField::class, $fields->dataFieldByName('StartTime'));
        $this->assertInstanceOf(ReadonlyField::class, $fields->dataFieldByName('VideoGuid'));
        $this->assertInstanceOf(ReadonlyField::class, $fields->dataFieldByName('StatusLabel'));
        $this->assertNotNull($fields->dataFieldByName('PosterImage'), 'poster upload field is kept');

        # The raw JSON and the synced numbers are not editable
        $this->assertNull($fields->dataFieldByName('PlayerOptions'));
        foreach (['Status', 'Duration', 'Width', 'Height', 'EncodeProgress', 'StorageSize'] as $name) {
            $this->assertNull($fields->dataFieldByName($name), "$name is not an editable field");
        }

        $preview = $fields->fieldByName('Root.Main.VideoPreview');
        $this->assertInstanceOf(LiteralField::class, $preview);
        $this->assertStringContainsString('<iframe src="https://iframe.mediadelivery.net/embed/', $preview->getContent());
        $this->assertNull($fields->fieldByName('Root.Main.StatusBanner'));
        $this->assertNull($fields->fieldByName('Root.Usages'));
    }

    public function testCmsFieldsResyncAVideoStillProcessing()
    {
        $video = $this->makeVideo(['Status' => BunnyStreamClient::STATUS_UPLOADED]);
        MockBunnyClient::queue(new Response(200, [], json_encode([
            'status' => BunnyStreamClient::STATUS_TRANSCODING,
            'encodeProgress' => 40,
        ])));

        $fields = $video->getCMSFields();

        $this->assertCount(1, MockBunnyClient::$history, 'an unfinished video is re-synced on CMS open');
        $banner = $fields->fieldByName('Root.Main.StatusBanner');
        $this->assertInstanceOf(LiteralField::class, $banner);
        $this->assertStringContainsString('Transcoding... (40%)', $banner->getContent());
        $this->assertNull($fields->fieldByName('Root.Main.VideoPreview'));
    }

    public function testCmsFieldsSurviveAnUnreachableApi()
    {
        $video = $this->makeVideo(['Status' => BunnyStreamClient::STATUS_UPLOADED]);
        MockBunnyClient::queue(new Response(500, [], 'down'));

        $fields = $video->getCMSFields();

        $this->assertNotNull($fields->fieldByName('Root.Main.StatusBanner'));
        $this->assertEquals(BunnyStreamClient::STATUS_UPLOADED, $video->Status);
    }

    public function testCmsFieldsDoNotResyncAFailedVideo()
    {
        foreach ([BunnyStreamClient::STATUS_ERROR, BunnyStreamClient::STATUS_UPLOAD_FAILED] as $status) {
            MockBunnyClient::reset();
            # A response IS available, so a sync attempt would be recorded rather than failing
            # silently on an empty mock queue (which getCMSFields() would swallow)
            MockBunnyClient::queue(new Response(200, [], json_encode(['status' => BunnyStreamClient::STATUS_FINISHED])));
            $video = $this->makeVideo(['Status' => $status]);

            $video->getCMSFields();

            $this->assertCount(0, MockBunnyClient::$history, "status $status is terminal: no re-sync");
            $this->assertEquals($status, $video->Status);
        }
    }

    public function testCmsFieldsListUsages()
    {
        $video = $this->makeVideo(['Status' => BunnyStreamClient::STATUS_FINISHED]);
        VideoHolder::create(['Title' => 'A', 'VideoID' => $video->ID])->write();

        $fields = $video->getCMSFields();

        $tab = $fields->findOrMakeTab('Root.Usages');
        $this->assertSame('Gebruikt door (1)', $tab->Title());
        $grid = $fields->dataFieldByName('Usages_' . str_replace('\\', '_', VideoHolder::class));
        $this->assertNotNull($grid);
        $this->assertSame(1, $grid->getList()->count());
    }

    // -------------------------------------------------------------------------
    // Delete lifecycle
    // -------------------------------------------------------------------------

    public function testDeletingAVideoWithoutGuidStaysLocal()
    {
        $video = BunnyVideo::create(['Title' => 'No guid']);
        $video->write();
        $id = $video->ID;

        $video->delete();

        $this->assertNull(BunnyVideo::get()->byID($id));
        $this->assertCount(0, MockBunnyClient::$history);
    }

    public function testDeleteRemovesTheRemoteVideoFirst()
    {
        $this->pushControllerWithSession();
        $video = $this->makeVideo();
        $id = $video->ID;
        MockBunnyClient::queue(new Response(200, [], ''));

        $video->delete();

        $this->assertNull(BunnyVideo::get()->byID($id));
        $request = MockBunnyClient::$history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertStringEndsWith('/videos/guid-1', $request->getUri()->getPath());
    }

    /**
     * Regression: the session lookup called Controller::has_curr(), which framework 6 removed, so
     * deleting a video outside a request (a task, a queued job, a test) was a fatal error there.
     */
    public function testDeleteWorksWithoutAController()
    {
        $video = $this->makeVideo();
        $id = $video->ID;
        MockBunnyClient::queue(new Response(200, [], ''));

        # SapphireTest's bootstrap pushes a dummy controller on Silverstripe 5, so the stack has to
        # be emptied explicitly to reach the "no controller" path (and restored afterwards). With
        # an empty stack a bare Controller::curr() warns on 5, which PHPUnit 9 turns into an error.
        $popped = [];
        while ($controller = static::currentControllerOrNull()) {
            $controller->popCurrent();
            $popped[] = $controller;
        }
        try {
            $video->delete();
        } finally {
            foreach (array_reverse($popped) as $controller) {
                $controller->pushCurrent();
            }
        }

        $this->assertNull(BunnyVideo::get()->byID($id));
    }

    /**
     * The current controller without Silverstripe 5's empty-stack warning (has_curr() is gone in 6).
     */
    protected static function currentControllerOrNull(): ?Controller
    {
        if (method_exists(Controller::class, 'has_curr')) {
            return Controller::has_curr() ? Controller::curr() : null;
        }
        return Controller::curr();
    }

    public function testFailedRemoteDeleteKeepsTheRecordAndRemembersTheError()
    {
        $session = $this->pushControllerWithSession();
        $video = $this->makeVideo();
        $id = $video->ID;
        MockBunnyClient::queue(new Response(500, [], 'boom'));

        try {
            $video->delete();
            $this->fail('A failed remote delete must abort the local delete');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(static::validationExceptionClass(), $e);
            $this->assertStringContainsString('Verwijderen op Bunny Stream mislukt', $e->getMessage());
        }

        $this->assertNotNull(BunnyVideo::get()->byID($id), 'the local record is kept');
        $this->assertNotEmpty($session->get("BunnyVideo.lastDeleteError.$id"));

        # The next CMS render offers the force-local-delete escape hatch
        $video->Status = BunnyStreamClient::STATUS_FINISHED;
        $fields = $video->getCMSFields();
        $this->assertNotNull($fields->fieldByName('Root.Main.BunnyDeleteErrorAlert'));
        $this->assertInstanceOf(CheckboxField::class, $fields->dataFieldByName('ForceLocalDelete'));
    }

    public function testForcedLocalDeleteSkipsTheApi()
    {
        $session = $this->pushControllerWithSession();
        $video = $this->makeVideo();
        $id = $video->ID;

        # Ticking the checkbox and saving stores the choice on the session
        $video->ForceLocalDelete = true;
        $video->write();
        $this->assertTrue($session->get("BunnyVideo.forceLocalDelete.$id"));

        $video->delete();

        $this->assertNull(BunnyVideo::get()->byID($id));
        $this->assertCount(0, MockBunnyClient::$history, 'the Bunny API was not called');
        $this->assertNull($session->get("BunnyVideo.forceLocalDelete.$id"), 'the flag is cleared');
    }
}
