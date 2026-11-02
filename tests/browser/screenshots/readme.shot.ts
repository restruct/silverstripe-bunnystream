import { test, expect, type Page } from '@playwright/test';
import * as path from 'path';

// Generates the README screenshots in docs/images/. Not a test suite: run it with
// playwright.screenshots.config.ts (see README.md next to this file). The content comes from the
// browser fixtures: opening /admin/bnb-showcase seeds BnBShowcaseLesson's lessons and videos.
//
// The host has no Bunny credentials and nothing here reaches Bunny. The two things the CMS loads
// from Bunny's hosts are answered locally, and both are stand-ins, said so in the README's alt text:
// - thumbnails (vz-<library>.b-cdn.net/<guid>/thumbnail.jpg): a plain colour gradient per video;
// - the player iframe (iframe.mediadelivery.net/embed/...): a dark placeholder frame.

const IMAGES = path.join(__dirname, '..', '..', '..', 'docs', 'images');

/** One gradient pair per showcase GUID (BnBShowcaseLesson::VIDEOS), so the posters differ. */
const POSTER_COLOURS: Record<string, [string, string]> = {
    '5a5a5a5a-0001-4000-8000-000000000001': ['#2f6f8f', '#9ad0c2'],
    '5a5a5a5a-0002-4000-8000-000000000002': ['#5b4b8a', '#c3a6e0'],
    '5a5a5a5a-0003-4000-8000-000000000003': ['#8a5a2b', '#f0c987'],
    '5a5a5a5a-0004-4000-8000-000000000004': ['#2e6b3f', '#a8d5a2'],
    '5a5a5a5a-0005-4000-8000-000000000005': ['#6b2e3f', '#e3a1b0'],
};

/** A 16:9 SVG "poster": a gradient with a soft circle, no text, no imagery. */
function poster(guid: string): string {
    const [a, b] = POSTER_COLOURS[guid] ?? ['#555555', '#aaaaaa'];
    return `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180" viewBox="0 0 320 180">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>
<rect width="320" height="180" fill="url(#g)"/><circle cx="230" cy="70" r="46" fill="#ffffff" fill-opacity="0.18"/>
<circle cx="90" cy="140" r="70" fill="#000000" fill-opacity="0.08"/></svg>`;
}

/** Answer Bunny's thumbnail and player hosts locally (see the header). */
async function standInForBunny(page: Page): Promise<void> {
    await page.route(/^https:\/\/vz-[^/]+\.b-cdn\.net\//, (route) => {
        const guid = new URL(route.request().url()).pathname.split('/')[1] ?? '';
        return route.fulfill({ status: 200, contentType: 'image/svg+xml', body: poster(guid) });
    });
    await page.route(/^https:\/\/iframe\.mediadelivery\.net\//, (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: `<!doctype html><html><body style="margin:0;height:100vh;background:#1d1f24;display:flex;align-items:center;justify-content:center">
<div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center">
<div style="margin-left:6px;width:0;height:0;border-left:20px solid rgba(255,255,255,.75);border-top:12px solid transparent;border-bottom:12px solid transparent"></div>
</div></body></html>`,
        }),
    );
}

/**
 * Hide what only the test host has: the browser-test fixture section ("BunnyStream browser test").
 * The showcase's own "Lessons" section stays: it stands for the project's own records. Presentation
 * only; nothing about the module's own UI is changed.
 */
async function hideFixtureChrome(page: Page): Promise<void> {
    await page.addStyleTag({ content: '.cms-menu li:has(> a[href*="admin/bnb-browser"]) { display: none !important; }' });
}

/** Wait until every <img> on the page has loaded. */
async function imagesLoaded(page: Page): Promise<void> {
    await expect
        .poll(() => page.evaluate(() => Array.from(document.images).every((i) => i.complete && i.naturalWidth > 0)), {
            message: 'all images loaded',
        })
        .toBe(true);
}

/** No hover state, no focus ring: park the mouse and blur, then let transitions settle. */
async function settle(page: Page): Promise<void> {
    await page.mouse.move(1270, 790);
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    await page.waitForTimeout(500);
}

test.beforeEach(async ({ page }) => {
    await standInForBunny(page);
    // Seeds the showcase on first open (BnBShowcaseAdmin::init()).
    await page.goto('/admin/bnb-showcase/lessons');
    await expect(page.locator('#Form_EditForm tr.ss-gridfield-item').first()).toBeVisible();
});

/** Open a showcase lesson's edit form. */
async function openLesson(page: Page, title: string): Promise<void> {
    await page.goto('/admin/bnb-showcase/lessons');
    await page.locator('#Form_EditForm tr.ss-gridfield-item', { hasText: title }).click();
    await expect(page.locator('#Form_ItemEditForm_BunnyVideoID_wrapper')).toBeVisible();
}

test('Video section: every video with its poster, status and metadata', async ({ page }) => {
    await page.goto('/admin/videos');
    await expect(page.locator('#Form_EditForm tr.ss-gridfield-item')).toHaveCount(5);
    await hideFixtureChrome(page);
    await imagesLoaded(page);
    await settle(page);

    // The window down to just below the list: the empty panel underneath adds nothing.
    const grid = (await page.locator('#Form_EditForm .grid-field__table').boundingBox())!;
    await page.screenshot({
        path: path.join(IMAGES, 'video-admin.png'),
        clip: { x: 0, y: 0, width: 1280, height: Math.ceil(grid.y + grid.height + 24) },
    });
});

test('Upload field: a lesson with its video attached', async ({ page }) => {
    await openLesson(page, 'Lesson 1: Getting started');
    await expect(page.locator('#Form_ItemEditForm_BunnyVideoID_preview')).toContainText('Welcome to the course');
    await hideFixtureChrome(page);
    await imagesLoaded(page);
    await settle(page);

    // Ends just below the field, before the next CMS menu entry starts.
    const holder = (await page.locator('#Form_ItemEditForm_BunnyVideoID_Holder').boundingBox())!;
    await page.screenshot({
        path: path.join(IMAGES, 'upload-field-attached.png'),
        clip: { x: 0, y: 0, width: 1280, height: Math.ceil(holder.y + holder.height + 12) },
    });
});

test('Upload field: no video yet, a file picked', async ({ page }) => {
    await openLesson(page, 'Lesson 3: Templates');
    const wrapper = page.locator('#Form_ItemEditForm_BunnyVideoID_wrapper');
    // Only the picked file's name is shown; the field uploads nothing until its button is clicked.
    await page.locator('#Form_ItemEditForm_BunnyVideoID_file').setInputFiles({
        name: 'templates-walkthrough.mp4',
        mimeType: 'video/mp4',
        buffer: Buffer.from('not really a video'),
    });
    await expect(page.locator('#Form_ItemEditForm_BunnyVideoID_btn')).toBeEnabled();
    await expect(page.locator('#Form_ItemEditForm_BunnyVideoID_status')).toHaveText('templates-walkthrough.mp4');
    await settle(page);

    // Just the field: label, file input, button and the picked file's name.
    const holder = (await page.locator('#Form_ItemEditForm_BunnyVideoID_Holder').boundingBox())!;
    const box = (await wrapper.boundingBox())!;
    await page.screenshot({
        path: path.join(IMAGES, 'upload-field-empty.png'),
        clip: {
            x: Math.floor(holder.x),
            y: Math.floor(holder.y - 8),
            width: Math.ceil(Math.min(box.x + 640, holder.x + holder.width) - holder.x),
            height: Math.ceil(holder.height + 16),
        },
    });
});

test('A video\'s edit form: player, player options and metadata', async ({ page }) => {
    // Tall enough for the whole form without scrolling the CMS's inner panel.
    await page.setViewportSize({ width: 1280, height: 1300 });
    await page.goto('/admin/videos');
    await page.locator('#Form_EditForm tr.ss-gridfield-item', { hasText: 'Setting up your workspace' }).click();
    await expect(page.locator('#Form_ItemEditForm_Title')).toHaveValue('Setting up your workspace');
    await expect(page.locator('#Form_ItemEditForm iframe')).toBeVisible();
    await hideFixtureChrome(page);
    await imagesLoaded(page);
    await settle(page);

    // The whole window: the form ends with the poster image field, just above the action bar.
    await expect(page.locator('#Form_ItemEditForm_PosterImage_Holder')).toBeInViewport({ ratio: 1 });
    await page.screenshot({ path: path.join(IMAGES, 'video-edit.png') });
});
