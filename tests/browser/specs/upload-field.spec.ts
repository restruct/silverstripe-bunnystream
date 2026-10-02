import { test, expect, field, openHolder, save, videoId } from './support';

// BunnyUploadField in a CMS edit form (client/dist/js/bunny-upload-field.js): the attached video's
// preview, unlinking it, picking a file, a refused upload, and a complete upload.

test('an attached video shows its preview and hides the upload controls', async ({ page }) => {
    // The module script is a static file from the module's exposed client/dist.
    const script = page.waitForResponse((r) => /silverstripe-bunnystream\/client\/dist\/js\/bunny-upload-field\.js/.test(r.url()));
    await openHolder(page, 'With video');
    expect((await script).status(), 'bunny-upload-field.js').toBe(200);

    const f = field(page);
    await expect(f.preview).toBeVisible();
    await expect(f.preview).toContainText('Ready video');
    await expect(f.preview).toContainText('Gereed');
    await expect(f.preview).toContainText('2:05');
    await expect(f.preview.locator('img')).toHaveAttribute('src', /\.b-cdn\.net\/0b0b0b0b-1111-4111-8111-000000000001\/thumbnail\.jpg$/);
    await expect(f.remove).toBeVisible();
    await expect(f.upload).toBeHidden();
    expect(await f.hidden.inputValue(), 'the relation is in the hidden input').toMatch(/^\d+$/);
});

test('"Ontkoppelen" clears the relation; saving keeps it cleared and the video itself stays', async ({ page }) => {
    await openHolder(page, 'Unlink target');
    const f = field(page);
    await expect(f.preview).toBeVisible();

    await f.remove.click();
    await expect(f.hidden).toHaveValue('');
    await expect(f.preview).toBeHidden();
    await expect(f.upload).toBeVisible();
    await save(page);

    await page.reload();
    await expect(field(page).wrapper).toBeVisible();
    await expect(field(page).preview).toHaveCount(0);
    await expect(field(page).upload).toBeVisible();
    await expect(field(page).hidden).toHaveValue(/^0?$/);

    // Unlinking only clears the relation: the video is still listed in the video admin.
    const readyId = await videoId(page, 'Ready video');
    expect(readyId).toBeGreaterThan(0);

    // Link it again, so a repeat run (--repeat-each) starts from the seeded state.
    await openHolder(page, 'Unlink target');
    await field(page).hidden.evaluate((el, id) => ((el as HTMLInputElement).value = String(id)), readyId);
    await save(page);
});

test('picking a file enables the upload button and shows the file name', async ({ page }) => {
    await openHolder(page, 'Upload target');
    const f = field(page);
    await expect(f.upload).toBeVisible();
    await expect(f.button).toBeDisabled();
    await f.file.setInputFiles({ name: 'clip.mp4', mimeType: 'video/mp4', buffer: Buffer.from('not really a video') });
    await expect(f.button).toBeEnabled();
    await expect(f.status).toHaveText('clip.mp4');
});

test('an upload without the security token is refused and the controls come back', async ({ page, allowConsoleError }) => {
    // Real server round trip: the field's request goes to the module's createUpload with its
    // SecurityID removed on the way (a forged cross-site request has no token). createUpload checks
    // the token before it talks to Bunny, so the module answers 400 and the API is never called
    // (issue #7). The script must turn that into a readable message and give the controls back.
    allowConsoleError(/status of 400 .*\/createUpload/);
    await page.route(/\/field\/BunnyVideoID\/createUpload/, (route) => {
        const url = new URL(route.request().url());
        expect(url.searchParams.get('SecurityID'), 'the field sends a token').toBeTruthy();
        url.searchParams.delete('SecurityID');
        return route.continue({ url: url.toString() });
    });
    await openHolder(page, 'Refused upload');
    const f = field(page);
    await f.file.setInputFiles({ name: 'clip.mp4', mimeType: 'video/mp4', buffer: Buffer.from('not really a video') });

    const refused = page.waitForResponse((r) => /\/field\/BunnyVideoID\/createUpload/.test(r.url()));
    await f.button.click();
    expect((await refused).status()).toBe(400);
    await expect(f.status).toContainText('beveiligingstoken ontbreekt of is verlopen');
    await expect(f.button).toBeEnabled();
    await expect(f.file).toBeEnabled();
    await expect(f.hidden).toHaveValue(/^0?$/);
});

test('a complete upload sets the new video, and saving links it', async ({ page, baseURL }) => {
    // The browser half of the flow, end to end: createUpload is answered here with the TUS
    // credentials Bunny would hand out (pointing at an existing video record), and the TUS upload
    // itself goes to a same-origin stub. What is real: the field's request (title + the form's
    // security token), tus-js-client driving the upload, the progress bar, the hidden input and
    // the save that links the video.
    const spareId = await videoId(page, 'Spare video');
    await openHolder(page, 'Upload target');
    const f = field(page);
    const token = await page.locator('#Form_ItemEditForm input[name="SecurityID"]').inputValue();

    let createUrl = '';
    await page.route(/\/field\/BunnyVideoID\/createUpload/, (route) => {
        createUrl = route.request().url();
        return route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                videoGuid: '0b0b0b0b-2222-4222-8222-000000000002',
                bunnyVideoId: spareId,
                tusEndpoint: `${baseURL}/bnb-tus-stub/`,
                tusHeaders: { AuthorizationSignature: 'sig', AuthorizationExpire: '9999999999', VideoId: 'x', LibraryId: '0' },
            }),
        });
    });
    // A minimal TUS server: creation (POST, 201 + Location), then the single PATCH (204 + offset).
    const tusRequests: string[] = [];
    await page.route(/\/bnb-tus-stub\//, (route) => {
        const req = route.request();
        tusRequests.push(req.method());
        const headers = { 'Tus-Resumable': '1.0.0' };
        if (req.method() === 'POST') {
            return route.fulfill({ status: 201, headers: { ...headers, Location: `${baseURL}/bnb-tus-stub/upload-1` } });
        }
        if (req.method() === 'PATCH') {
            const offset = Number(req.headers()['upload-offset'] ?? 0) + (req.postDataBuffer()?.length ?? 0);
            return route.fulfill({ status: 204, headers: { ...headers, 'Upload-Offset': String(offset) } });
        }
        return route.fulfill({ status: 404 });
    });

    await f.file.setInputFiles({ name: 'clip.mp4', mimeType: 'video/mp4', buffer: Buffer.alloc(4096, 1) });
    await f.button.click();

    await expect(f.result).toBeVisible();
    await expect(f.result).toContainText('Video geüpload');
    await expect(f.progress).toHaveText('100%');
    await expect(f.hidden).toHaveValue(String(spareId));
    await expect(f.button).toBeDisabled();
    await expect(f.file).toBeDisabled();
    const query = new URL(createUrl).searchParams;
    expect(query.get('title'), 'createUpload gets the file name as title').toBe('clip.mp4');
    expect(query.get('SecurityID'), 'createUpload carries the form\'s security token').toBe(token);
    expect(tusRequests, 'TUS creation, then the upload').toEqual(['POST', 'PATCH']);

    await save(page);
    await page.reload();
    await expect(field(page).preview).toBeVisible();
    await expect(field(page).preview).toContainText('Spare video');

    // Unlink again, so a repeat run (--repeat-each) starts from the seeded state.
    await field(page).remove.click();
    await save(page);
});
