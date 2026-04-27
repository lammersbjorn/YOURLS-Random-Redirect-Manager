import { test, expect } from '../utils/fixtures';

const ADMIN_PATH = '/admin/plugins.php?page=random_redirect_settings';

test.describe.configure({ mode: 'serial' });

test.describe('A configured keyword actually redirects', () => {
  const stamp = Date.now().toString(36);
  const keyword = `rrm-redir-${stamp}`;
  const targetUrls = [
    `https://example.com/rrm-redir-${stamp}-A`,
    `https://example.com/rrm-redir-${stamp}-B`,
  ];

  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext({
      baseURL: process.env.YOURLS_BASE_URL ?? 'http://127.0.0.1:8080',
      storageState: '.auth/admin.json',
    });
    const page = await ctx.newPage();

    await page.goto(ADMIN_PATH);
    await page.locator('input[name="new_list_keyword"]').fill(keyword);
    // Fill the first URL row, then click "Add URL" once to get a second
    // input, then fill that.
    const newListUrlInputs = page.locator('input[name="new_list_urls[]"]');
    await newListUrlInputs.first().fill(targetUrls[0]);
    // The "Add URL" button inside the new-list form is the last .add-url on
    // the page; click it and then fill the freshly added row.
    await page.locator('button.add-url').last().click();
    await newListUrlInputs.nth(1).fill(targetUrls[1]);

    await page.locator('input[type="submit"][value*="Save"]').first().click();
    await page.waitForLoadState('networkidle');
    await ctx.close();
  });

  test('hitting the keyword bounces to one of the configured URLs', async ({ browser }) => {
    // Use a clean context (no auth, no cookies) so the request looks like a
    // real public click on the shortlink.
    const ctx = await browser.newContext({
      baseURL: process.env.YOURLS_BASE_URL ?? 'http://127.0.0.1:8080',
    });
    const page = await ctx.newPage();

    // The plugin uses yourls_redirect() with a 307 — Playwright follows
    // redirects automatically, so we capture the final URL of the
    // navigation.
    const response = await page.goto(`/${keyword}`, { waitUntil: 'commit' });
    // Some browsers may not reach a final loaded state for example.com (it
    // doesn't actually serve the path), so we check the response chain
    // captured by Playwright.
    const finalUrl = response?.url() ?? page.url();

    expect(targetUrls).toContain(finalUrl);
    await ctx.close();
  });
});
