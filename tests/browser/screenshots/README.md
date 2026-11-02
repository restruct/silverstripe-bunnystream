# README screenshots

`readme.shot.ts` writes the PNGs in `docs/images/` that the module README shows. It is not a test
suite: it runs with its own config, `playwright.screenshots.config.ts`, which the browser tests and
CI never load, so a normal test run never rewrites the images. Never edit the PNGs by hand;
regenerate them.

What it needs:

- a Silverstripe 6 host with this module installed and the browser fixtures from
  `tests/browser/fixtures/` copied into its `app/src/`, then the database built. No Bunny
  credentials: the host must not have any. Opening `/admin/bnb-showcase` (the script does this
  first) seeds the demo content from `BnBShowcaseLesson`: five videos with made-up GUIDs and three
  lessons. That **replaces** the videos the browser specs seed, so run a fresh database build
  before running the specs again (the shared runner builds on every run);
- `BROWSER_TARGET_URLS` pointing at that host (only the `ss6` target is used, else the first).

Nothing reaches Bunny. The CMS loads two things from Bunny's hosts, and the script answers both
itself: thumbnails get a plain colour gradient per video, the player iframe a dark placeholder. The
README says so next to the screenshots. Every video the script opens is "finished", so the CMS
never asks the API for its status.

The shared runner in the maintainers' workspace does all of that:

```bash
BROWSER_ONLY=ss6 tools/browser/run.sh silverstripe-bunnystream --config=playwright.screenshots.config.ts
```

Against a host you started yourself, from this directory:

```bash
npm ci
BROWSER_TARGET_URLS="ss6=http://127.0.0.1:8866" npx playwright test --config=playwright.screenshots.config.ts
```
