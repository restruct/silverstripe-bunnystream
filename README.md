Bunny Stream for Silverstripe
=============================

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

Upload, manage and embed videos hosted on [Bunny Stream](https://bunny.net/stream/) from the
Silverstripe CMS. The video file never touches your server: the browser uploads it straight to
Bunny (resumable TUS upload), and Silverstripe keeps a `BunnyVideo` record with the video's GUID and
the metadata Bunny reports back.

What you get:

* **`BunnyVideo`**, a DataObject per video: title, description, status, duration, dimensions,
  storage size, an optional poster image, and per-video player options.
* **`BunnyUploadField`**, a form field for a `has_one` to `BunnyVideo`: pick a file, it uploads to
  Bunny with a progress bar, and the relation is set when it finishes.
* **`VideoAdmin`**, a "Video's" section in the CMS listing every video with its thumbnail, status,
  duration, size and dimensions.
* **`BunnyStreamClient`**, a small client for the Bunny Stream API (create, get, list, delete),
  TUS upload signing and (signed) embed URLs.

The CMS labels are Dutch and not translatable yet.

## Requirements

* Silverstripe 5 or 6 (`silverstripe/framework`, `silverstripe/admin`, `silverstripe/assets`)
* PHP 8.1 or newer
* A Bunny Stream video library

## Installation

```
composer require restruct/silverstripe-bunnystream
```

Set the environment variables below, then run `sake dev/build flush=1` (Silverstripe 5) or
`sake db:build --flush` (Silverstripe 6).

## Version compatibility

| Branch | Module version | Silverstripe | PHP |
|--------|----------------|--------------|-----|
| `main` | `1.x` | `^5 \|\| ^6` | `^8.1` |
| (tags only) | `0.1.x` | `^5` | `^8.1` |

`main` is the only maintained line: it supports every Silverstripe version this module targets, so
there is no separate maintenance branch. A version branch will be created only when a change cannot
be made compatible across the supported range. Silverstripe 4 is not supported.

**`composer.json` is the source of truth** for exact constraints; this table is a quick reference.
Upgrading from `0.1.x`: see [UPGRADING.md](UPGRADING.md).

## Configuration

All configuration is through environment variables (`.env`):

| Variable | Required | What it does |
|----------|----------|--------------|
| `BUNNY_STREAM_API_KEY` | yes | The library's API key (Bunny dashboard > Stream > your library > API). |
| `BUNNY_STREAM_LIBRARY_ID` | yes | The numeric library ID. |
| `BUNNY_STREAM_CDN_HOSTNAME` | no | Pull-zone hostname for **thumbnails**. Default: `vz-<library ID>.b-cdn.net`. Embeds always use `iframe.mediadelivery.net`. |
| `BUNNY_STREAM_TOKEN_AUTH_KEY` | no | The library's "Token Authentication Key". When set, **and** token authentication is enabled on the library, embed URLs are signed and expire (4 hours by default). Without it, anyone with the embed URL can play the video. |

## Usage

### Attach a video to a record

Add a `has_one` to `BunnyVideo` and use `BunnyUploadField` for it:

```php
use Restruct\BunnyStream\Forms\BunnyUploadField;
use Restruct\BunnyStream\Model\BunnyVideo;

class Lesson extends DataObject
{
    private static $has_one = [
        'BunnyVideo' => BunnyVideo::class,
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->replaceField(
            'BunnyVideoID',
            BunnyUploadField::create('BunnyVideoID', 'Video')
                ->setDescription('MP4, MOV, ...')
        );
        return $fields;
    }
}
```

What happens on upload:

1. The field calls its `createUpload` action, which creates the video on Bunny, writes a
   `BunnyVideo` record (status "created") and returns short-lived TUS credentials.
2. The browser uploads the file directly to Bunny with [tus-js-client](https://github.com/tus/tus-js-client)
   (loaded from jsDelivr), showing progress.
3. When it finishes, the new record's ID is put in the field, so saving the form sets the relation.

With a video attached the field shows its thumbnail, title, status and duration, and an
"Ontkoppelen" button that clears the relation (the video itself is kept).

`createUpload` is reachable by anyone who can reach the form. In the CMS that means CMS users; do
not put the field on a public front-end form.

### Embed a video

```php
$video->getPlayerIframeHTML();                     // responsive 16:9 <iframe> in a .ratio wrapper
$video->getPlayerIframeHTML(['autoplay' => true, 'muted' => true, 'loop' => true, 'controls' => false]);
$video->getPlayerURL();                            // the embed URL alone, signed when configured
$video->getThumbnailUrl();
```

`getPlayerIframeHTML()` returns `''` when the record has no video. `autoplay` is always sent
(default `false`) so a library-level autoplay setting does not apply unless you ask for it. In a
template, `$BunnyVideo.PlayerIframeHTML` outputs the markup (it is cast as HTML).

### Per-video player options

Each video carries a small JSON store of player options, editable in the CMS under "Afspeelopties":

| Option | CMS field | Default | Effect |
|--------|-----------|---------|--------|
| `rememberPosition` | Positie onthouden | on | Bunny embed parameter: resume where the viewer stopped. Always sent, so a per-video "off" overrides the library setting. |
| `t` | Starttijd | none | Bunny embed parameter: start offset, eg `90s`, `1m30s`, `hh:mm:ss`. |
| `enforceFullWatch` | Volledig bekijken afdwingen | off | Emitted as a `data-enforce-full-watch="1"` attribute on the iframe. **The module does not enforce it**: Bunny has no "disable seeking" parameter, so your front-end JavaScript has to read the attribute and act on it (eg through Bunny's player.js API). |

From PHP: `getPlayerOption($key, $default)`, `setPlayerOption($key, $value)` (`null` removes the
key), `getPlayerQueryParams()` and `getPlayerDataAttributes()`. Other keys can be stored too; only
the three above are acted on.

### Status and metadata

Bunny processes a video asynchronously (created, uploaded, processing, transcoding, finished, or
error / upload failed). Until a video is finished, opening it in the CMS refreshes its metadata from
the API; an unreachable API leaves the stored values in place rather than breaking the form. Call
`refreshFromApi()` yourself to sync outside the CMS (it writes the record). `isReady()` is true once
Bunny reports the video finished.

### Usages

A video's edit form has a "Gebruikt door" tab listing every record that points at it through a
`has_one`, found by scanning the data model, so the module needs no configuration for your classes.
The same list is available as `getUsages()`.

### Deleting

Deleting a `BunnyVideo` deletes the video on Bunny **first**. If that fails, the local delete is
aborted (a `ValidationException` is thrown) and the error is remembered in the session: the next
time the video is opened, the form shows the error and a "Forceer lokale verwijdering" checkbox.
Ticking it and saving makes the next delete skip the API (a warning is logged, and the remote video
is left behind for manual cleanup). A record without a GUID is simply deleted locally.

### The API client

```php
use Restruct\BunnyStream\Api\BunnyStreamClient;

$client = BunnyStreamClient::create();             // credentials from the environment
$client->createVideo('Title', $collectionId = null);
$client->getVideo($guid);
$client->listVideos($page = 1, $perPage = 100, $search = null);
$client->deleteVideo($guid);
$client->isReady($guid);
$client->getTusUploadCredentials($guid, $expiresInSeconds = 3600);
$client->getEmbedUrl($guid, $expiresInSeconds = 14400);
```

The module always instantiates the client through the Injector, so a project can replace it:

```yaml
SilverStripe\Core\Injector\Injector:
  Restruct\BunnyStream\Api\BunnyStreamClient:
    class: App\Video\LoggingBunnyClient
```

API errors surface as Guzzle exceptions.

## Running the tests

The suite needs a booted Silverstripe project. Install the module into a host project as a
**symlinked** path repository (`/tests` is `export-ignore`, so a dist install has no tests), then from
the host root:

```
# Silverstripe 5 (PHPUnit 9): flush with a trailing flush=1 AFTER the test path
vendor/bin/phpunit vendor/restruct/silverstripe-bunnystream/tests flush=1

# Silverstripe 6 (PHPUnit 11): flush through the environment
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit vendor/restruct/silverstripe-bunnystream/tests
```

No request reaches Bunny: the tests swap in a mock HTTP transport. `.github/workflows/ci.yml` builds
such a host project per Silverstripe major.

## License

MIT, see [LICENSE](LICENSE).
