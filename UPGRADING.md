# Upgrading

## 0.1.x -> 1.0

**1.0 supports Silverstripe 5 and 6** from one line. On Silverstripe 5 it is a drop-in upgrade.

```
composer require restruct/silverstripe-bunnystream:^1
```

A `~0.1.3` or `^0.1` constraint will not pick up 1.0; change it deliberately.

### Run dev/build

`BunnyVideo.StorageSize` changes from `Int` to `BigInt` so videos over 2 GiB can be stored. Run
`sake dev/build flush=1` (Silverstripe 5) or `sake db:build --flush` (Silverstripe 6) to widen the
column; existing values are kept.

On Silverstripe 5, 0.1.x stored any size over 2 GiB as `2147483647` (MySQL clamped it). Widening the
column does not change that value. A video that is still processing picks up its real size the next
time it is opened in the CMS, but a **finished video is never re-synced automatically**, so it keeps
showing 2.00 GB until `refreshFromApi()` is called on it. To correct all of them once, after
dev/build (for example from a one-off BuildTask or a PHP shell):

```php
foreach (\Restruct\BunnyStream\Model\BunnyVideo::get()->filter('StorageSize', 2147483647) as $video) {
    $video->refreshFromApi(); # fetches the video from Bunny and writes the record
}
```

### Requirements

`silverstripe/admin` is now a hard requirement (it always was in practice: `VideoAdmin` extends
`ModelAdmin`). PHP `^8.1`.

### If you catch the delete exception

A failed remote delete still throws a `ValidationException`, but its class follows the framework:
`SilverStripe\ORM\ValidationException` on 5, `SilverStripe\Core\Validation\ValidationException` on 6.
Catch the one for your framework version.

### If you instantiate the client

`new BunnyStreamClient()` still works. The module itself now uses `BunnyStreamClient::create()`, so
an Injector override of `Restruct\BunnyStream\Api\BunnyStreamClient` applies to everything the module
does.
