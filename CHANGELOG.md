# Changelog

## 1.2.0 (unreleased)

### Fixed

- **Editors without ADMIN could not manage videos**
  ([#6](https://github.com/restruct/silverstripe-bunnystream/issues/6)). `BunnyVideo` had no
  permission methods, so only ADMIN could create, edit or delete a video, while the Video's section
  admits `CMS_ACCESS_LeftAndMain`. `canView()`, `canEdit()`, `canCreate()` and `canDelete()` now
  require exactly what opening `VideoAdmin` requires: ADMIN or `CMS_ACCESS_LeftAndMain`, or else
  all of `VideoAdmin.required_permission_codes` (falling back to
  `CMS_ACCESS_Restruct\BunnyStream\Admin\VideoAdmin` when that config is empty or `false`). A
  project that changes the section's permission therefore changes the records' too. Each asks
  extensions first, so a project can change them per method.
- `createUpload()` refused a crafted request with an array `title` (`title[]=x`) only by a
  `TypeError` (500). It now answers 400 before anything is sent to Bunny.

### Security

- **`createUpload()` now requires access to the Video's section, not just any CMS access**
  ([#6](https://github.com/restruct/silverstripe-bunnystream/issues/6)). It checks
  `BunnyVideo::canCreate()` instead of `Permission::check('CMS_ACCESS')`. Since 1.1.0 an editor with
  access to any one CMS section (Pages, say) could register videos; that editor is now refused
  (403), because the same rule governs editing and deleting the video, and a delete also deletes it
  on Bunny. **If editors of other sections upload through a `BunnyUploadField` in their forms**,
  give them the section's permission (by default `CMS_ACCESS_LeftAndMain`, or the code you set in
  `VideoAdmin.required_permission_codes`), or widen `canCreate()` with an extension.
- **tus-js-client is no longer loaded from a CDN**
  ([#5](https://github.com/restruct/silverstripe-bunnystream/issues/5)). `BunnyUploadField` loaded
  `tus-js-client@4` from jsDelivr, at a floating major and without subresource integrity, into the
  CMS with the editor's session. It now ships an unmodified, pinned copy (4.3.1, MIT, licence
  included) in `client/dist/js/vendor/tus-js-client/`, exposed like the field's own script. The CMS
  no longer needs to reach `cdn.jsdelivr.net`. How to verify or update the copy: the README next to
  it.

## 1.1.0 (2026-09-25)

### Security

- `BunnyUploadField::createUpload()` created a video on Bunny and a `BunnyVideo` record for any
  request that reached the field's URL, with no permission or CSRF check
  ([#7](https://github.com/restruct/silverstripe-bunnystream/issues/7)). It now requires CMS access
  (`Permission::check('CMS_ACCESS')`: ADMIN or any `CMS_ACCESS_*` code, so editors of other CMS
  sections are not locked out) and a valid `SecurityID` token. The field renders the token in a
  `data-security-token` attribute and its JavaScript sends it along.

### Changed

- `createUpload` now refuses requests: 403 without CMS access, 400 without a valid token. A custom
  JavaScript caller of the endpoint must send the token as the `SecurityID` query var (or an
  `X-SecurityID` header), read from the field's `data-security-token` attribute.
- The field's JavaScript shows a readable message when `createUpload` refuses a request, instead of
  a JSON parse error.

## 1.0.0 (2026-09-25)

**Silverstripe 5 and 6.** One line, `main`, supports both. Upgrade guide: [UPGRADING.md](UPGRADING.md).

Requires PHP `^8.1`, `silverstripe/framework ^5 || ^6`, `silverstripe/admin ^2 || ^3` and
`silverstripe/assets ^2 || ^3`.

### Fixed

- **Silverstripe 6: the module did not work.** Opening a video in the CMS, deleting a video, and
  rendering `BunnyUploadField` were all fatal errors on framework 6: the code called
  `Controller::has_curr()` and `FormField::Value()`, both removed in 6, and threw
  `SilverStripe\ORM\ValidationException`, which moved to `SilverStripe\Core\Validation`.
- **`silverstripe/admin` was not required** although `VideoAdmin` extends `ModelAdmin`, so on a
  project without it a flush was a fatal error ([#1](https://github.com/restruct/silverstripe-bunnystream/issues/1)).
- **Videos over 2 GiB could not be stored.** `StorageSize` was a 32-bit `Int`. On Silverstripe 6
  the write failed validation, and because the CMS swallows sync errors such a video silently
  stopped updating its status; on 5, MySQL clamped the size to 2 GiB. It is now a `BigInt`.
  Finished videos that were clamped keep the old value until `refreshFromApi()` runs; see
  [UPGRADING.md](UPGRADING.md).
- `BunnyUploadField` echoed its value into the markup unescaped; after a failed submit that value
  is whatever the browser posted.

### Changed

- `BunnyStreamClient` is instantiated through the Injector (`BunnyStreamClient::create()`), so a
  project can replace it.
- `composer.json`: PHP floor made explicit, `funding` entry, `1.x-dev` branch alias.

### Added

- `$BunnyVideo.PlayerIframeHTML` works in templates: it is now cast as HTML instead of being
  escaped as text.
- Test suite (59 tests, identical on Silverstripe 5 and 6) with a regression test for each fix above, and a
  GitHub Actions matrix: Silverstripe 5 on PHP 8.1 and 8.3, Silverstripe 6 on PHP 8.3 and 8.4.
- README (there was none): configuration, usage, player options, the delete flow, the API client,
  running the tests, and a version compatibility table. MIT `LICENSE` file.

### Notes

- Consumers counted locally only: the one known consumer project is on Silverstripe 5 with
  `~0.1.3`, which does not pick up 1.0.0. No hosting-wide inventory was made.

## 0.1.3 and earlier

- Silverstripe 5 only. Initial releases: upload field with direct TUS upload, `VideoAdmin`, signed
  embeds, per-video player options, fail-closed delete propagation.
