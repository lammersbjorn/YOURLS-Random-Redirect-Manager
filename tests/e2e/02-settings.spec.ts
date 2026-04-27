import { test, expect } from '../utils/fixtures';

const ADMIN_PATH = '/admin/plugins.php?page=random_redirect_settings';

test.describe.configure({ mode: 'serial' });

test.describe('Adding and editing redirect lists via the admin form', () => {
  // Use a stamped keyword so re-runs of the suite don't collide. The plugin
  // also creates a YOURLS shortlink for the keyword, so colliding with a
  // previous run would otherwise hit the "keyword already exists" branch.
  const stamp = Date.now().toString(36);
  const keyword = `rrm${stamp}`;
  const targetUrl = `https://example.com/rrm-${stamp}-target`;

  test('a brand-new redirect list can be added and persists', async ({ page, errors }) => {
    await page.goto(ADMIN_PATH);

    // Fill in the "Add New Redirect List" section. There may be more than
    // one row of url-inputs on the page (one per existing list), so target
    // the new-list form via the input names which are unique to it.
    await page.locator('input[name="new_list_keyword"]').fill(keyword);
    await page.locator('input[name="new_list_urls[]"]').first().fill(targetUrl);
    await page.locator('input[type="submit"][value*="Save"]').first().click();
    await page.waitForLoadState('networkidle');

    // After save, the page reloads and the list shows up as an existing
    // redirect-list-settings card with the keyword we just entered.
    await expect(
      page.locator('.redirect-list-settings', { hasText: keyword }).first()
    ).toBeVisible();

    // Reload — the list should still be there.
    await page.goto(ADMIN_PATH);
    await expect(
      page.locator('.redirect-list-settings', { hasText: keyword }).first()
    ).toBeVisible();

    expect(errors.serverErrors).toEqual([]);
  });

  test('the list data round-trips through the form', async ({ page, errors }) => {
    await page.goto(ADMIN_PATH);

    // The keyword input for an existing list carries a name like
    // list_keyword[<keyword>]. Locate it and confirm it has the right value.
    const keywordInput = page.locator(`input[name="list_keyword[${keyword}]"]`);
    await expect(keywordInput).toHaveValue(keyword);

    // The first URL input for that list should have the target URL.
    const urlInput = page.locator(`input[name="list_urls[${keyword}][]"]`).first();
    await expect(urlInput).toHaveValue(targetUrl);

    expect(errors.serverErrors).toEqual([]);
  });

  test('a list can be deleted from the admin form', async ({ page, errors }) => {
    await page.goto(ADMIN_PATH);

    // The delete button is a submit input with name=delete_list and the
    // keyword as its value. It triggers a window.confirm in the UI; auto-
    // accept it.
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator(`button[name="delete_list"][value="${keyword}"]`).click();
    await page.waitForLoadState('networkidle');

    await expect(
      page.locator('.redirect-list-settings', { hasText: keyword })
    ).toHaveCount(0);

    expect(errors.serverErrors).toEqual([]);
  });
});
