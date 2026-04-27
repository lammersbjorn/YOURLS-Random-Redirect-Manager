import { test, expect } from '../utils/fixtures';

const ADMIN_PATH = '/admin/plugins.php?page=random_redirect_settings';

test.describe('Plugin basics', () => {
  test('YOURLS admin still loads with the plugin active', async ({ page, errors }) => {
    const response = await page.goto('/admin/index.php');
    expect(response?.status()).toBeLessThan(400);
    await expect(page.locator('#new_url_form')).toBeVisible();
    expect(errors.serverErrors).toEqual([]);
  });

  test('plugin appears as Active on plugins page', async ({ page }) => {
    await page.goto('/admin/plugins.php');
    await expect(
      page.locator('tr.plugin.active', { hasText: 'Random Redirect Manager' })
    ).toBeVisible();
  });

  test('settings page renders the configuration form', async ({ page, errors }) => {
    await page.goto(ADMIN_PATH);
    await expect(page.locator('#random-redirect-form')).toBeVisible();
    // The "Add New Redirect List" form must be present so users can create
    // their first list from a fresh install.
    await expect(page.locator('input[name="new_list_keyword"]')).toBeVisible();
    await expect(page.locator('input[type="submit"][value*="Save"]')).toBeVisible();
    expect(errors.serverErrors).toEqual([]);
  });

  test('settings page heading + add-button are wired up', async ({ page, errors }) => {
    await page.goto(ADMIN_PATH);
    await expect(page.locator('h2', { hasText: /Random Redirect Manager/i })).toBeVisible();
    // The "Add URL" button starts with at least one matching button on the page.
    await expect(page.locator('button.add-url').first()).toBeVisible();
    expect(errors.serverErrors).toEqual([]);
  });
});
