import { test as base, expect, type Locator, type Page } from '@playwright/test';

// Shared fixtures and helpers for the bunnystream specs.
//
// Fixtures (tests/browser/fixtures/, copied into the scratch host by the runner): BnBHolder records
// with a BunnyUploadField at /admin/bnb-browser/holders, and three "finished" BunnyVideo records the
// module's VideoAdmin lists at /admin/videos. The host has no Bunny credentials and nothing in these
// specs reaches Bunny: thumbnail and player requests to Bunny's hosts are answered here, and the
// one upload spec answers createUpload and the TUS upload itself.

/** A 1x1 transparent PNG, served for every Bunny thumbnail request. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64');

/**
 * test, extended with:
 * - bunnyHosts (auto): answers requests to Bunny's thumbnail CDN (vz-<library>.b-cdn.net) and
 *   player (iframe.mediadelivery.net) locally, so no spec depends on Bunny or the network.
 * - consoleGuard (auto): every spec fails if the page logs a console error or throws an uncaught
 *   exception, page load included ("Failed to load resource" for any 4xx/5xx counts too).
 *   allowConsoleError(re) whitelists one expected error, e.g. the 400 a refused upload answers.
 */
export const test = base.extend<{ bunnyHosts: void; consoleGuard: void; allowConsoleError: (re: RegExp) => void }>({
    bunnyHosts: [
        async ({ page }, use) => {
            await page.route(/^https:\/\/vz-[^/]+\.b-cdn\.net\//, (route) =>
                route.fulfill({ status: 200, contentType: 'image/png', body: PNG }),
            );
            await page.route(/^https:\/\/iframe\.mediadelivery\.net\//, (route) =>
                route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>player stub</title>' }),
            );
            await use();
        },
        { auto: true },
    ],
    // The whitelist lives on the function itself, so consoleGuard (which depends on this fixture
    // and therefore gets the same instance as the spec) can read it after the spec ran.
    allowConsoleError: async ({}, use) => {
        const list: RegExp[] = [];
        await use(Object.assign((re: RegExp) => void list.push(re), { list }));
    },
    consoleGuard: [
        async ({ page, allowConsoleError }, use, testInfo) => {
            const allowed = (allowConsoleError as unknown as { list: RegExp[] }).list;
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            const unexpected = errors.filter((e) => !allowed.some((re) => re.test(e)));
            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(unexpected, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };

/** The upload field of a holder's edit form (field name BunnyVideoID). */
export function field(page: Page): {
    wrapper: Locator; hidden: Locator; preview: Locator; remove: Locator; upload: Locator;
    file: Locator; button: Locator; status: Locator; progress: Locator; result: Locator;
} {
    const id = 'Form_ItemEditForm_BunnyVideoID';
    return {
        wrapper: page.locator(`#${id}_wrapper`),
        hidden: page.locator(`#${id}`),
        preview: page.locator(`#${id}_preview`),
        remove: page.locator(`#${id}_remove`),
        upload: page.locator(`#${id}_upload`),
        file: page.locator(`#${id}_file`),
        button: page.locator(`#${id}_btn`),
        status: page.locator(`#${id}_status`),
        progress: page.locator(`#${id}_progress .progress-bar`),
        result: page.locator(`#${id}_result`),
    };
}

/** Open a seeded holder's edit form from the fixture ModelAdmin, the way an editor does. */
export async function openHolder(page: Page, title: string): Promise<void> {
    await page.goto('/admin/bnb-browser/holders');
    await page.locator('#Form_EditForm_holders tr.ss-gridfield-item').filter({
        has: page.locator('td.col-Title', { hasText: new RegExp(`^\\s*${title}\\s*$`) }),
    }).click();
    await expect(field(page).wrapper).toBeVisible();
}

/** Open a seeded video's edit form from the module's VideoAdmin. */
export async function openVideo(page: Page, title: string): Promise<void> {
    await page.goto('/admin/videos');
    await page.locator('#Form_EditForm tr.ss-gridfield-item', { hasText: title }).click();
    await expect(page.locator('#Form_ItemEditForm_Title')).toHaveValue(title);
}

/** The record ID of a seeded video, read from its VideoAdmin row. */
export async function videoId(page: Page, title: string): Promise<number> {
    await page.goto('/admin/videos');
    return Number(await page.locator('#Form_EditForm tr.ss-gridfield-item', { hasText: title }).getAttribute('data-id'));
}

/**
 * Click the edit form's Save button and wait for the save round trip: an AJAX POST of the item
 * form answered with 200.
 */
export async function save(page: Page): Promise<void> {
    const posted = page.waitForResponse((r) => r.request().method() === 'POST' && /\/ItemEditForm(\?|$)/.test(r.url()));
    await page.locator('#Form_ItemEditForm button[name="action_doSave"]').click();
    const response = await posted;
    expect(['xhr', 'fetch'], 'the save is an AJAX request').toContain(response.request().resourceType());
    expect(response.status(), 'save status').toBe(200);
}
