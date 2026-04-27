import { chromium, request, FullConfig } from '@playwright/test';
import { mkdir } from 'node:fs/promises';

const BASE_URL = process.env.YOURLS_BASE_URL ?? 'http://127.0.0.1:8080';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin';
const PLUGIN_FOLDER = 'Random-Redirect-Manager';
const PLUGIN_NAME = 'Random Redirect Manager';

async function waitForYourls() {
  const ctx = await request.newContext({ baseURL: BASE_URL });
  for (let i = 0; i < 90; i++) {
    try {
      const r = await ctx.get('/admin/install.php', { maxRedirects: 0 });
      const status = r.status();
      // 200 / 302 once YOURLS is running. 503 is what YOURLS returns from the
      // installer page before the database tables exist — the install flow
      // we kick off below still works against it.
      if (status === 200 || status === 302 || status === 503) {
        await ctx.dispose();
        return;
      }
    } catch {
      /* still booting */
    }
    await new Promise((res) => setTimeout(res, 2000));
  }
  await ctx.dispose();
  throw new Error(`YOURLS at ${BASE_URL} did not become reachable`);
}

export default async function globalSetup(_config: FullConfig) {
  await waitForYourls();

  const browser = await chromium.launch();
  const context = await browser.newContext({ baseURL: BASE_URL });
  const page = await context.newPage();

  // 1. Run YOURLS installer if not already installed.
  await page.goto('/admin/install.php');
  const installButton = page
    .locator('input[type=submit][value*="Install" i], button:has-text("Install YOURLS")')
    .first();
  if (await installButton.isVisible({ timeout: 5000 }).catch(() => false)) {
    await installButton.click();
    await page.waitForLoadState('networkidle');
  }

  // 2. Log in to the admin (form-based session cookie).
  await page.goto('/admin/');
  const usernameInput = page.locator('input[name=username]');
  if (await usernameInput.isVisible({ timeout: 5000 }).catch(() => false)) {
    await usernameInput.fill(ADMIN_USER);
    await page.locator('input[name=password]').fill(ADMIN_PASS);
    const loginButton = page
      .locator('input[type=submit][value*="Login" i], input[type=submit][name="submit"], button[type=submit]')
      .first();
    await Promise.all([
      page.waitForLoadState('networkidle'),
      loginButton.click(),
    ]);
  }

  // Confirm we are actually logged in. /admin/index.php only renders the
  // new-link form (#new_url_form) and the admin nav (#admin_menu) when a
  // valid session cookie is present.
  await page.goto('/admin/index.php');
  const loggedIn = await page
    .locator('#new_url_form, #admin_menu')
    .first()
    .isVisible({ timeout: 5000 })
    .catch(() => false);
  if (!loggedIn) {
    throw new Error(
      'Admin login failed — no admin menu / new-URL form visible after sign-in.'
    );
  }

  // 3. Activate the plugin if not already active.
  await page.goto('/admin/plugins.php');
  const activateLink = page.locator(
    `a[href*="action=activate"][href*="plugin=${PLUGIN_FOLDER}"]`
  );
  if ((await activateLink.count()) > 0) {
    const href = await activateLink.first().getAttribute('href');
    if (href) {
      await page.goto(href);
      await page.waitForLoadState('networkidle');
    }
  }

  const activated = await page
    .locator('div.notice', { hasText: /plugin has been activated/i })
    .or(page.locator('tr.plugin.active', { hasText: PLUGIN_NAME }))
    .first()
    .isVisible({ timeout: 5000 })
    .catch(() => false);
  if (!activated) {
    throw new Error(
      `Plugin "${PLUGIN_FOLDER}" did not activate — check that the plugin folder is mounted at user/plugins/${PLUGIN_FOLDER}.`
    );
  }

  // 4. Optionally activate Sleeky-backend so we exercise the plugin against
  //    the popular admin theme as well.
  if (process.env.SLEEKY_ENABLED === 'true') {
    await page.goto('/admin/plugins.php');
    const sleekyActivate = page.locator(
      'a[href*="action=activate"][href*="plugin=sleeky-backend"]'
    );
    if ((await sleekyActivate.count()) > 0) {
      const href = await sleekyActivate.first().getAttribute('href');
      if (href) {
        await page.goto(href);
        await page.waitForLoadState('networkidle');
      }
    } else {
      throw new Error(
        'SLEEKY_ENABLED=true but no sleeky-backend activate link found — was the plugin folder mounted into the YOURLS container?'
      );
    }

    const sleekyActivated = await page
      .locator('tr.plugin.active', { hasText: 'Sleeky Backend' })
      .first()
      .isVisible({ timeout: 5000 })
      .catch(() => false);
    if (!sleekyActivated) {
      throw new Error('Sleeky-backend did not activate.');
    }
  }

  // Persist the authenticated state so individual specs do not have to repeat
  // the install / login dance.
  await mkdir('.auth', { recursive: true });
  await context.storageState({ path: '.auth/admin.json' });
  await browser.close();
}
