import { test, expect, openVideo, save } from './support';

// The module's VideoAdmin ("Video's", /admin/videos) and a video's edit form.

test('the video list shows poster, title, status, duration, size and dimensions', async ({ page }) => {
    await page.goto('/admin/videos');
    const row = page.locator('#Form_EditForm tr.ss-gridfield-item', { hasText: 'Ready video' });
    await expect(row).toBeVisible();
    await expect(row.locator('img')).toHaveAttribute('src', /\.b-cdn\.net\/0b0b0b0b-1111-4111-8111-000000000001\/thumbnail\.jpg$/);
    await expect(row).toContainText('Gereed');
    await expect(row).toContainText('2:05');
    await expect(row).toContainText('50.0 MB');
    await expect(row).toContainText('1920 × 1080');
});

test('a finished video\'s form shows the player and the records that use it', async ({ page }) => {
    await openVideo(page, 'Ready video');
    const player = page.locator('#Form_ItemEditForm iframe');
    await expect(player).toHaveAttribute('src', /^https:\/\/iframe\.mediadelivery\.net\/embed\/\d+\/0b0b0b0b-1111-4111-8111-000000000001\?/);
    await expect(page.locator('#Form_ItemEditForm_VideoGuid')).toContainText('0b0b0b0b-1111-4111-8111-000000000001');

    // "Gebruikt door (n)": every has_one pointing at this video, found by scanning the data model.
    const tab = page.getByRole('tab', { name: /^Gebruikt door \(\d+\)$/ });
    await tab.click();
    await expect(page.locator('#Root_Usages')).toContainText('With video');
});

test('player options saved in the CMS end up in the player URL', async ({ page }) => {
    await openVideo(page, 'Options video');
    const player = page.locator('#Form_ItemEditForm iframe');
    // Defaults: autoplay always sent (false), rememberPosition on.
    await expect(player).toHaveAttribute('src', /[?&]autoplay=false(&|$)/);
    await expect(player).toHaveAttribute('src', /[?&]rememberPosition=true(&|$)/);

    // Untick "Positie onthouden", set a start time, save: the reloaded form's player carries both.
    const remember = page.locator('#Form_ItemEditForm_RememberPosition');
    if (await remember.isChecked()) await remember.uncheck();
    await page.locator('#Form_ItemEditForm_StartTime').fill('90s');
    await save(page);
    await page.reload();
    await expect(page.locator('#Form_ItemEditForm iframe')).toHaveAttribute('src', /[?&]rememberPosition=false(&|$)/);
    await expect(page.locator('#Form_ItemEditForm iframe')).toHaveAttribute('src', /[?&]t=90s(&|$)/);
    await expect(page.locator('#Form_ItemEditForm_RememberPosition')).not.toBeChecked();

    // Back to the defaults, so a repeat run starts from the same state.
    await page.locator('#Form_ItemEditForm_RememberPosition').check();
    await page.locator('#Form_ItemEditForm_StartTime').fill('');
    await save(page);
});
